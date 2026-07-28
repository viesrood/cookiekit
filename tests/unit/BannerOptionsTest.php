<?php

declare(strict_types=1);

namespace viesrood\cookiekit\tests\unit;

use PHPUnit\Framework\TestCase;
use viesrood\cookiekit\helpers\BannerOptions;

final class BannerOptionsTest extends TestCase
{
    /**
     * @return array{template: string, registerCss: bool, language: string}
     */
    private function defaults(string $template = '', bool $registerCss = true, string $language = ''): array
    {
        return ['template' => $template, 'registerCss' => $registerCss, 'language' => $language];
    }

    public function testEmptyOptionsGiveTheBuiltInBehaviour(): void
    {
        $resolved = BannerOptions::resolve([], $this->defaults());

        self::assertNull($resolved['template']);
        self::assertTrue($resolved['registerJs']);
        self::assertTrue($resolved['registerCss']);
        self::assertNull($resolved['language']);
    }

    public function testRegisterAssetsStillTurnsOffBoth(): void
    {
        $resolved = BannerOptions::resolve(['registerAssets' => false], $this->defaults());

        self::assertFalse($resolved['registerJs']);
        self::assertFalse($resolved['registerCss']);
    }

    /**
     * The whole point of the split: your own styles, the plugin's script.
     */
    public function testTheStylesheetCanBeDroppedOnItsOwn(): void
    {
        $resolved = BannerOptions::resolve(['registerCss' => false], $this->defaults());

        self::assertTrue($resolved['registerJs']);
        self::assertFalse($resolved['registerCss']);
    }

    public function testTheScriptCanBeDroppedOnItsOwn(): void
    {
        $resolved = BannerOptions::resolve(['registerJs' => false], $this->defaults());

        self::assertFalse($resolved['registerJs']);
        self::assertTrue($resolved['registerCss']);
    }

    public function testTheFinerSwitchesBeatRegisterAssets(): void
    {
        $resolved = BannerOptions::resolve(
            ['registerAssets' => false, 'registerJs' => true],
            $this->defaults(),
        );

        self::assertTrue($resolved['registerJs']);
        self::assertFalse($resolved['registerCss']);
    }

    public function testTheStylesheetSettingIsTheFallback(): void
    {
        $resolved = BannerOptions::resolve([], $this->defaults(registerCss: false));

        self::assertFalse($resolved['registerCss']);
        self::assertTrue($resolved['registerJs'], 'the script is never turned off by a setting');
    }

    public function testAnOptionBeatsTheStylesheetSetting(): void
    {
        $resolved = BannerOptions::resolve(['registerCss' => true], $this->defaults(registerCss: false));

        self::assertTrue($resolved['registerCss']);
    }

    /**
     * `registerCss: false` has to survive the null-coalescing chain, which is
     * exactly what a naive `?? $default` gets wrong.
     */
    public function testAnExplicitFalseIsNotMistakenForAbsent(): void
    {
        $resolved = BannerOptions::resolve(['registerCss' => false], $this->defaults(registerCss: true));

        self::assertFalse($resolved['registerCss']);
    }

    public function testTheTemplateSettingIsUsedWhenNoOptionIsGiven(): void
    {
        $resolved = BannerOptions::resolve([], $this->defaults(template: '_cookiekit/banner'));

        self::assertSame('_cookiekit/banner', $resolved['template']);
    }

    public function testTheTemplateOptionBeatsTheSetting(): void
    {
        $resolved = BannerOptions::resolve(
            ['template' => '_cookiekit/modal'],
            $this->defaults(template: '_cookiekit/banner'),
        );

        self::assertSame('_cookiekit/modal', $resolved['template']);
    }

    public function testAnEmptyTemplateFallsBackRatherThanRenderingNothing(): void
    {
        self::assertNull(BannerOptions::resolve(['template' => ''], $this->defaults())['template']);
        self::assertNull(BannerOptions::resolve(['template' => '   '], $this->defaults())['template']);

        self::assertSame(
            '_cookiekit/banner',
            BannerOptions::resolve(['template' => ''], $this->defaults(template: '_cookiekit/banner'))['template'],
        );
    }

    public function testTheLanguageSettingIsUsedWhenNoOptionIsGiven(): void
    {
        self::assertSame('nl', BannerOptions::resolve([], $this->defaults(language: 'nl'))['language']);
    }

    public function testTheLanguageOptionBeatsTheSetting(): void
    {
        $resolved = BannerOptions::resolve(['language' => 'nl-BE'], $this->defaults(language: 'fr'));

        self::assertSame('nl-BE', $resolved['language']);
    }

    /**
     * Passing null explicitly is how a per-site expression says "do not force".
     */
    public function testAnExplicitNullLanguageFallsBackToTheSetting(): void
    {
        self::assertSame('nl', BannerOptions::resolve(['language' => null], $this->defaults(language: 'nl'))['language']);
    }

    public function testAnUnusableLanguageIsIgnoredRatherThanPassedOn(): void
    {
        self::assertNull(BannerOptions::resolve(['language' => 'not a language'], $this->defaults())['language']);
    }
}
