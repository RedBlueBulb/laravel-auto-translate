<?php

namespace Ben182\AutoTranslate;

use Illuminate\Support\Arr;
use Themsaid\Langman\Manager as Langman;
use Ben182\AutoTranslate\Translators\TranslatorInterface;

class AutoTranslate
{
    public $manager;
    public $translator;

    public function __construct(Langman $manager, TranslatorInterface $translator)
    {
        $this->manager = $manager;
        $this->translator = $translator;
        $this->translator->setSource(config('auto-translate.source_language'));
    }

    public function getSourceTranslations()
    {
        return $this->getTranslations(config('auto-translate.source_language'));
    }

    public function getTranslations(string $lang)
    {
        $aReturn = [];

        $files = $this->manager->files();

        foreach ($files as $fileKeyName => $languagesFile) {
            if (! isset($languagesFile[$lang])) {
                continue;
            }

            $allTranslations = $this->manager->getFileContent($languagesFile[$lang]);

            $aReturn[$fileKeyName] = $allTranslations;
        }

        return $aReturn;
    }

    public function getUpdatedTranslations(string $lang)
    {
        $sourceLang = config('auto-translate.source_language');
        $sourceTranslations = $this->getSourceTranslations();

        $files = $this->manager->files();
        $langTranslations = [];
        foreach ($files as $fileKeyName => $languagesFile) {
            //If there is no corresponding file for this lang, continue
            //(meaning the whole source lang file will get translated)
            if (! isset($languagesFile[$lang])) {
                continue;
            }

            //If there is no corresponding file for the source lang, continue
            //(this should not happen)
            if (! isset($languagesFile[$sourceLang])) {
                continue;
            }

            //If the file for the source lang is newer than the file for this lang, continue
            //(meaning the whole source lang file will get translated)
            if(filemtime($languagesFile[$sourceLang]) > filemtime($languagesFile[$lang])){
                continue;
            }

            //If we get here it should mean that no translation is necessary - so revert to MissingTranslations behaviour
            $trans = $this->manager->getFileContent($languagesFile[$lang]);
            $langTranslations[$fileKeyName] = $trans;
        }

        $dottedSource = Arr::dot($sourceTranslations);
        $dottedlang = Arr::dot($langTranslations);

        $diff = array_diff(array_keys($dottedSource), array_keys($dottedlang));

        return collect($dottedSource)->only($diff);
    }

    public function getMissingTranslations(string $lang)
    {
        $source = $this->getSourceTranslations();
        $lang = $this->getTranslations($lang);

        $dottedSource = Arr::dot($source);
        $dottedlang = Arr::dot($lang);

        $diff = array_diff(array_keys($dottedSource), array_keys($dottedlang));

        return collect($dottedSource)->only($diff);
    }

    public function translate(string $targetLanguage, $data, $callbackAfterEachTranslation = null)
    {
        $this->translator->setTarget($targetLanguage);

        $dottedSource = Arr::dot($data);

        foreach ($dottedSource as $key => $value) {
            if ($value === '') {
                $dottedSource[$key] = $value;

                if ($callbackAfterEachTranslation) {
                    $callbackAfterEachTranslation();
                }
                continue;
            }

            $variables = $this->findVariables($value);

            $dottedSource[$key] = is_string($value) ? $this->translator->translate($value) : $value;

            $dottedSource[$key] = $this->replaceTranslatedVariablesWithOld($variables, $dottedSource[$key]);

            if ($callbackAfterEachTranslation) {
                $callbackAfterEachTranslation();
            }
        }

        return $this->array_undot($dottedSource);
    }

    public function findVariables($string)
    {
        $m = null;

        if (is_string($string)) {
            preg_match_all('/:\S+/', $string, $m);
        }

        return $m;
    }

    public function replaceTranslatedVariablesWithOld($variables, $string)
    {
        if (isset($variables[0])) {
            $replacements = $variables[0];

            return preg_replace_callback('/:\S+/', function ($matches) use (&$replacements) {
                return array_shift($replacements);
            }, $string);
        }
    }

    public function fillLanguageFiles(string $language, array $data)
    {
        foreach ($data as $languageFileKey => $translations) {
            $translations = array_map(function ($item) use ($language) {
                return [
                    $language => $item,
                ];
            }, $translations);

            $this->manager->fillKeys($languageFileKey, $translations);
        }
    }

    public function array_undot(array $dottedArray, array $initialArray = []) : array
    {
        foreach ($dottedArray as $key => $value) {
            Arr::set($initialArray, $key, $value);
        }

        return $initialArray;
    }
}
