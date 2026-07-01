<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Input;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

/**
 * Shared selection input for every command in the `dataset:` namespace — bind it with #[MapInput].
 * A command targets one dataset (positional), a whole provider (--provider), or everything (--all).
 *
 * `--dataset` is deliberately NOT offered here: inside the dataset bundle the dataset is the positional
 * argument. `--dataset` stays reserved for commands OUTSIDE the bundle (e.g. import:*), which act on an
 * already-chosen dataset rather than selecting one.
 *
 * MapInput instantiates this via newInstanceWithoutConstructor() and assigns the public properties
 * directly, so they must be public, non-readonly, and carry their own defaults.
 */
final class DatasetInputDTO
{
    #[Argument('Dataset key (provider/code) or a bare code — e.g. "mus/cleveland" or "cleveland"')]
    public ?string $dataset = null;

    #[Option('Fan out over every dataset for this provider — e.g. "smith"')]
    public ?string $provider = null;

    #[Option('Every registered dataset (all providers)')]
    public bool $all = false;
}
