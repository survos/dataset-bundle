<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Tests\Context;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Survos\DatasetBundle\Context\DatasetResolver;
use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class DatasetResolverTest extends TestCase
{
    #[Test]
    public function itResolvesDatasetOptionBeforeOtherAliases(): void
    {
        $resolver = new DatasetResolver(new DataPaths('/srv/app-data'));
        $input = new ArrayInput([
            '--dataset' => 'DC/TB09JW350',
            '--code' => 'ignored/value',
        ], $this->definition());

        self::assertSame('dc-tb09jw350', $resolver->resolveFromInput($input));
    }

    #[Test]
    public function itResolvesCodeAndTenantAliases(): void
    {
        $resolver = new DatasetResolver(new DataPaths('/srv/app-data'));

        self::assertSame('smith-objects', $resolver->resolveFromInput(new ArrayInput(['--code' => 'smith/objects'], $this->definition())));
        self::assertSame('tenant-tenant', $resolver->resolveFromInput(new ArrayInput(['--tenant' => 'tenant'], $this->definition())));
    }

    private function definition(): InputDefinition
    {
        return new InputDefinition([
            new InputOption('dataset', null, InputOption::VALUE_REQUIRED),
            new InputOption('code', null, InputOption::VALUE_REQUIRED),
            new InputOption('tenant', null, InputOption::VALUE_REQUIRED),
        ]);
    }
}
