<?php

namespace Drupal\Tests\blaetter_export_epub\Unit\Form;

use Drupal\blaetter_export_epub\Form\EpubSettingsForm;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests, that the pepgen salt is never shown in the settings form and can be set via settings.php.
 *
 * @group blaetter_export_epub
 */
#[CoversClass(EpubSettingsForm::class)]
#[Group('blaetter_export_epub')]
class EpubSettingsFormTest extends UnitTestCase
{
    /**
     * The editable configuration, as stored.
     */
    private $editable;

    /**
     * Creates the form with the given stored salt and the given salt from settings.php.
     *
     * @param string $stored_salt The salt stored in the configuration
     * @param string|null $override_salt The salt set in settings.php, null if there is no override
     */
    private function createForm(string $stored_salt, ?string $override_salt = null): EpubSettingsForm
    {
        $values = [
            'pepgen' => [
                'salt' => $stored_salt,
                'base_url' => 'https://pepgen.example.com',
                'download_url' => 'https://pepgen.example.com/download',
            ],
        ];
        $overrides = null === $override_salt ? [] : ['pepgen' => ['salt' => $override_salt]];

        $merged = NestedArray::mergeDeep($values, $overrides);
        $immutable = $this->getMockBuilder(ImmutableConfig::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'hasOverrides'])
            ->getMock();
        $immutable->method('get')->willReturnCallback(fn ($key = '') => NestedArray::getValue($merged, explode('.', $key)));
        $immutable->method('hasOverrides')->willReturnCallback(
            fn ($key = '') => NestedArray::keyExists($overrides, explode('.', $key))
        );

        $this->editable = new class ($values) {
            public $saved = false;

            public function __construct(public array $data)
            {
            }

            public function get($key)
            {
                return NestedArray::getValue($this->data, explode('.', $key));
            }

            public function set($key, $value)
            {
                NestedArray::setValue($this->data, explode('.', $key), $value);
                return $this;
            }

            public function save()
            {
                $this->saved = true;
                return $this;
            }
        };

        $config_factory = $this->createMock(ConfigFactoryInterface::class);
        $config_factory->method('get')->willReturn($immutable);
        $config_factory->method('getEditable')->willReturn($this->editable);

        $container = new ContainerBuilder();
        $container->set('config.factory', $config_factory);
        $container->set('string_translation', $this->getStringTranslationStub());
        $container->set('messenger', $this->createMock(MessengerInterface::class));
        \Drupal::setContainer($container);

        $form = new EpubSettingsForm($config_factory, $this->createMock(TypedConfigManagerInterface::class));
        $form->setStringTranslation($this->getStringTranslationStub());
        $form->setMessenger($container->get('messenger'));
        return $form;
    }

    /**
     * Returns the salt element of the form.
     */
    private function getSaltElement(EpubSettingsForm $form): array
    {
        return $form->buildForm([], new FormState())['pepgen']['salt'];
    }

    /**
     * Returns all texts and values of a form element, to check, that the salt is not part of it.
     */
    private function getElementText(array $element): string
    {
        $text = [];
        array_walk_recursive($element, function ($value) use (&$text) {
            $text[] = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
        });
        return implode("\n", $text);
    }

    /**
     * Submits the form with the given salt and returns the stored salt.
     */
    private function submit(EpubSettingsForm $form, ?string $salt): string
    {
        $values = [
            'base_url' => 'https://pepgen.example.org',
            'download_url' => 'https://pepgen.example.org/download',
        ];
        if (null !== $salt) {
            $values['salt'] = $salt;
        }
        $form_array = [];
        $form->submitForm($form_array, (new FormState())->setValues($values));

        $this->assertTrue($this->editable->saved);
        $this->assertSame('https://pepgen.example.org', $this->editable->get('pepgen.base_url'));
        return $this->editable->get('pepgen.salt');
    }

    /**
     * Tests, that the stored salt is not shown in the form.
     */
    public function testSaltIsNotShown(): void
    {
        $element = $this->getSaltElement($this->createForm('stored-salt'));

        $this->assertSame('password', $element['#type']);
        $this->assertArrayNotHasKey('#default_value', $element);
        $this->assertSame('new-password', $element['#attributes']['autocomplete']);
        $this->assertStringContainsString('A salt is stored.', (string) $element['#description']);
        $this->assertStringNotContainsString('stored-salt', $this->getElementText($element));
    }

    /**
     * Tests, that the form tells, if there is no salt yet.
     */
    public function testFormWithoutSalt(): void
    {
        $element = $this->getSaltElement($this->createForm(''));

        $this->assertStringContainsString('There is no salt stored yet.', (string) $element['#description']);
    }

    /**
     * Tests, that the form only shows a hint, if the salt is set via settings.php.
     */
    public function testFormWithOverriddenSalt(): void
    {
        $element = $this->getSaltElement($this->createForm('', 'salt-from-settings'));

        $this->assertSame('item', $element['#type']);
        $this->assertStringContainsString('settings.php', (string) $element['#markup']);
        $this->assertStringNotContainsString('salt-from-settings', $this->getElementText($element));
    }

    /**
     * Tests, which salt is stored.
     */
    public function testStoredSalt(): void
    {
        $this->assertSame('new-salt', $this->submit($this->createForm('stored-salt'), ' new-salt '), 'New salt');
        $this->assertSame('stored-salt', $this->submit($this->createForm('stored-salt'), ''), 'Empty field');
        $this->assertSame('', $this->submit($this->createForm(''), ''), 'No salt at all');

        // With the salt from settings.php the stored salt is not needed anymore and removed from the config.
        $this->assertSame('', $this->submit($this->createForm('stored-salt', 'salt-from-settings'), null));
    }
}
