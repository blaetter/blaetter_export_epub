<?php

namespace Drupal\Tests\blaetter_export_epub\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that the permissions required by the routes are defined.
 *
 * A permission that is required, but not defined, can not be granted to any role, so only user 1 has access.
 *
 * @group blaetter_export_epub
 */
#[CoversNothing]
#[Group('blaetter_export_epub')]
class PermissionsTest extends UnitTestCase
{
    /**
     * Returns the parsed yaml file of the module.
     */
    private function getModuleYaml(string $file): array
    {
        return Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/blaetter_export_epub.' . $file . '.yml'));
    }

    /**
     * Tests, that every permission of the static routes is defined.
     */
    public function testRoutePermissionsAreDefined(): void
    {
        $permissions = $this->getModuleYaml('permissions');
        $required = [];
        foreach ($this->getModuleYaml('routing') as $name => $route) {
            if (is_array($route) && isset($route['requirements']['_permission'])) {
                $required[$name] = $route['requirements']['_permission'];
            }
        }

        $this->assertSame(
            [
                'blaetter_export_epub.generate' => 'export epub',
                'blaetter_export_epub.settings' => 'administer blaetter export epub',
            ],
            $required
        );
        foreach ($required as $name => $permission) {
            $this->assertArrayHasKey($permission, $permissions, $name . ' requires an undefined permission.');
        }
    }

    /**
     * Tests, that the permissions are marked as restricted.
     *
     * The settings contain the pepgen salt and the export creates ePubs without watermark.
     */
    public function testPermissionsAreRestricted(): void
    {
        foreach ($this->getModuleYaml('permissions') as $name => $permission) {
            $this->assertTrue($permission['restrict access'] ?? false, $name . ' should be restricted.');
        }
    }
}
