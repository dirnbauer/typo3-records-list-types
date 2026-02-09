<?php

declare(strict_types=1);

namespace Webconsulting\RecordsListTypes\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture tests to enforce layer constraints in the extension.
 *
 * These rules are evaluated by PHPat via PHPStan.
 */
final class ArchitectureTest
{
    /**
     * Events must be pure PHP - no dependencies on services or controllers.
     */
    public function testEventsShouldNotDependOnServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\Event'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Webconsulting\RecordsListTypes\Service'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\Controller'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\EventListener'),
            );
    }

    /**
     * Services should not depend on controllers.
     * Services are lower-level; controllers are consumers of services.
     */
    public function testServicesShouldNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\Controller'));
    }

    /**
     * Event listeners should not depend on controllers.
     * They react to events dispatched by the framework, not by controllers directly.
     */
    public function testEventListenersShouldNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\EventListener'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\Controller'));
    }

    /**
     * ViewHelpers should not depend on services directly.
     * They should use event listeners or other lightweight mechanisms.
     */
    public function testViewHelpersShouldNotDependOnServices(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\ViewHelpers'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Webconsulting\RecordsListTypes\Service'));
    }

    /**
     * The Constants class should be standalone with no internal dependencies.
     */
    public function testConstantsShouldNotDependOnAnything(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('Webconsulting\RecordsListTypes\Constants'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Webconsulting\RecordsListTypes\Service'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\Controller'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\Event'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\EventListener'),
                Selector::inNamespace('Webconsulting\RecordsListTypes\ViewHelpers'),
            );
    }
}
