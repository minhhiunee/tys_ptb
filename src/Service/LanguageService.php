<?php

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;
use Photobooth\Logger\NamedLogger;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\RuntimeCacheUtility;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Translator;

class LanguageService
{
    private string $locale;
    /** @var array<string,string> */
    private array $catalog = [];
    /** @var array<string,int> */
    private array $definedKeys = [];
    private ?NamedLogger $logger = null;
    /** @var array<string,bool> */
    private array $missingLogged = [];

    public function __construct()
    {
        $this->locale = ConfigurationService::getInstance()->getConfiguration()['ui']['language'];

        $langDir = PathUtility::getAbsolutePath(FolderEnum::LANG->value);
        $enFile = $langDir . DIRECTORY_SEPARATOR . 'en.json';
        $localeFile = $langDir . DIRECTORY_SEPARATOR . $this->locale . '.json';

        $enMtime = is_file($enFile) ? ((int) @filemtime($enFile) . ':' . (int) @filesize($enFile)) : '0:0';
        $localeMtime = ($this->locale !== 'en' && is_file($localeFile))
            ? ((int) @filemtime($localeFile) . ':' . (int) @filesize($localeFile))
            : '0:0';
        $fingerprint = $this->locale . '|' . $enMtime . '|' . $localeMtime . '|' . (int) @filemtime(__FILE__);
        $cacheKey = 'lang_' . md5($this->locale . '|' . __DIR__);

        $cached = RuntimeCacheUtility::get($cacheKey, $fingerprint, 'ser');
        if (is_array($cached) && isset($cached['catalog'], $cached['definedKeys'])) {
            $this->catalog = $cached['catalog'];
            $this->definedKeys = $cached['definedKeys'];
            return;
        }

        $translator = new Translator($this->locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('json', new JsonFileLoader());

        if (PathUtility::isAbsolutePath($langDir)) {
            if (is_file($enFile)) {
                $translator->addResource('json', $enFile, 'en', 'photobooth');
            }
            if ($this->locale !== 'en' && is_file($localeFile)) {
                $translator->addResource('json', $localeFile, $this->locale, 'photobooth');
            }
        }

        $fallbackLang = $translator->getCatalogue('en')->all('photobooth');
        $currentLang = $translator->getCatalogue($this->locale)->all('photobooth');
        $this->catalog = array_replace($fallbackLang, $currentLang);
        $this->definedKeys = array_flip(array_keys($currentLang));

        RuntimeCacheUtility::set($cacheKey, $fingerprint, [
            'catalog' => $this->catalog,
            'definedKeys' => $this->definedKeys,
        ], 'ser');
    }

    public function translate(string $id): string
    {
        $translated = $this->catalog[$id] ?? $id;

        if (!isset($this->definedKeys[$id]) && !isset($this->missingLogged[$id])) {
            $this->missingLogged[$id] = true;
            $this->logger ??= LoggerService::getInstance()->getLogger('i18n');
            if ($this->logger->getLevel() >= 2) {
                $this->logger->debug('Missing translation', [
                    'key'    => $id,
                    'locale' => $this->locale,
                ]);
            }
        }

        return $translated;
    }

    public function all(): array
    {
        return $this->catalog;
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
