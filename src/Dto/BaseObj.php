<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Dto;

/**
 * Base class for all normalized collection object DTOs.
 *
 * Provides no fields — just a common type for type-checking and
 * service registration (e.g. DtoRegistry scanning for #[Mapper]).
 */
abstract class BaseObj
{
}
