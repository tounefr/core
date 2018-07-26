<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Hal\Serializer;

use ApiPlatform\Core\Api\OperationType;
use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\CachedResourceNameCollectionFactory;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Operation\Factory\SubresourceOperationFactoryInterface;
use ApiPlatform\Core\PathResolver\OperationPathResolverInterface;
use ApiPlatform\Core\Serializer\AbstractCollectionNormalizer;
use ApiPlatform\Core\Util\IriHelper;

/**
 * Normalizes collections in the HAL format.
 *
 * @author Kevin Dunglas <dunglas@gmail.com>
 * @author Hamza Amrouche <hamza@les-tilleuls.coop>
 */
final class CollectionNormalizer extends AbstractCollectionNormalizer
{
    const FORMAT = 'jsonhal';

    private $cachedResourceNameCollectionFactory;
    private $resourceMetadataFactory;
    private $operationPathResolver;
    private $subresourceOperationFactory;

    public function __construct(ResourceClassResolverInterface $resourceClassResolver, string $pageParameterName,
                                CachedResourceNameCollectionFactory $cachedResourceNameCollectionFactory,
                                ResourceMetadataFactoryInterface $resourceMetadataFactory,
                                OperationPathResolverInterface $operationPathResolver,
                                SubresourceOperationFactoryInterface $subresourceOperationFactory)
    {
        parent::__construct($resourceClassResolver, $pageParameterName);
        $this->cachedResourceNameCollectionFactory = $cachedResourceNameCollectionFactory;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->operationPathResolver = $operationPathResolver;
        $this->subresourceOperationFactory = $subresourceOperationFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function getPaginationData($object, array $context = []): array
    {
        list($paginator, $paginated, $currentPage, $itemsPerPage, $lastPage, $pageTotalItems, $totalItems) = $this->getPaginationConfig($object, $context);
        $parsed = IriHelper::parseIri($context['request_uri'] ?? '/', $this->pageParameterName);

        $data = [
            '_links' => [
                'self' => ['href' => IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $paginated ? $currentPage : null)],
            ],
        ];

        if ($paginated) {
            if (null !== $lastPage) {
                $data['_links']['first']['href'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, 1.);
                $data['_links']['last']['href'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $lastPage);
            }

            if (1. !== $currentPage) {
                $data['_links']['prev']['href'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $currentPage - 1.);
            }

            if ((null !== $lastPage && $currentPage !== $lastPage) || (null === $lastPage && $pageTotalItems >= $itemsPerPage)) {
                $data['_links']['next']['href'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $currentPage + 1.);
            }
        }

        if (null !== $totalItems) {
            $data['totalItems'] = $totalItems;
        }

        if ($paginator) {
            $data['itemsPerPage'] = (int) $itemsPerPage;
        }

        return $data;
    }

    private function getPath(string $resourceShortName, string $operationName, array $operation, string $operationType): string
    {
        $path = $this->operationPathResolver->resolveOperationPath($resourceShortName, $operation, $operationType, $operationName);
        if ('.{_format}' === substr($path, -10)) {
            $path = substr($path, 0, -10);
        }

        return $path;
    }

    private function camelCaseToSnakeCase($str) {
        $str[0] = strtolower($str[0]);
        $func = create_function('$c', 'return "_" . strtolower($c[1]);');
        return preg_replace_callback('/([A-Z])/', $func, $str);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTemplatedLinks($context)
    {
        $resourceNameCollection = $this->cachedResourceNameCollectionFactory->create();

        $paths = [];
        foreach ($resourceNameCollection as $resourceClass) {
            if ($resourceClass != $context['resource_class'])
                continue;
            $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);
            $resourceShortName = $resourceMetadata->getShortName();
            $operations = $resourceMetadata->getItemOperations();

            foreach ($operations as $operationName => $operation) {
                $path = $this->getPath($resourceShortName, $operationName, $operation, OperationType::ITEM);
                if (in_array($path, array_values($paths)))
                    continue;
                $resourceShortName = $this->camelCaseToSnakeCase($resourceShortName);
                $paths[$resourceShortName] = $path;

                if (null === $this->subresourceOperationFactory) {
                    continue;
                }

                foreach ($this->subresourceOperationFactory->create($resourceClass) as $operationId => $subresourceOperation) {
                    $path = $this->getPath($subresourceOperation['shortNames'][0], $subresourceOperation['route_name'], $subresourceOperation, OperationType::SUBRESOURCE);
                    if (in_array($path, array_values($paths)))
                        continue;
                    $subResourceShortName = $this->camelCaseToSnakeCase($subresourceOperation['shortNames'][0]);
                    $paths[$subResourceShortName] = $path;
                }
            }
        }

        $links = [];
        foreach ($paths as $pathName => $pathTemplate) {
            $links[$pathName] = [
                'href' => $pathTemplate,
                'templated' => true
            ];
        }

        return $links;
    }

    /**
     * {@inheritdoc}
     */
    protected function getItemsData($object, string $format = null, array $context = []): array
    {
        $data = [];
        $data['_links'] = $this->getTemplatedLinks($context);

        foreach ($object as $obj) {
            $item = $this->normalizer->normalize($obj, $format, $context);
            $data['_embedded']['item'][] = $item;
            $data['_links']['item'][] = $item['_links']['self'];
        }

        return $data;
    }
}
