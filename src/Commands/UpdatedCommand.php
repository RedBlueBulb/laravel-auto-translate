<?php

namespace Ben182\AutoTranslate\Commands;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Ben182\AutoTranslate\AutoTranslate;

class UpdatedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autotrans:updated';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translates all source translations that have been recently updated';

    protected $autoTranslator;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(AutoTranslate $autoTranslator)
    {
        parent::__construct();
        $this->autoTranslator = $autoTranslator;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $targetLanguages = Arr::wrap(config('auto-translate.target_language'));
        $paths = [config('auto-translate.path')];

        //Retrieve parent path
        $parentPath = config('auto-translate.parent_path', '');
        if($parentPath){
            $iterator = new \DirectoryIterator($parentPath);
            foreach($iterator as $fileinfo) {
                if(!$fileinfo->isDot() && $fileinfo->isDir()){
                    $paths[] = $fileinfo->getPathName();
                }
            }
        }

        foreach($paths as $path){

            //Override path
			$this->autoTranslator->manager->setPath($path);

            $foundLanguages = count($targetLanguages);
            $this->line("Searching in $path");
            $this->line('Found '.$foundLanguages.' '.Str::plural('language', $foundLanguages).' to translate');

            $missingCount = 0;
            $strLen = 0;
            foreach ($targetLanguages as $targetLanguage) {
                $missing = $this->autoTranslator->getMissingTranslations($targetLanguage);
                $missingCount += $missing->count();
                $strLen += $missing->map(function ($value) {
                    if(!is_array($value)){
                        return strlen($value);
                    }

                    return 0;
                })->sum();
                $this->line('Found '.$missing->count().' missing keys in '.$targetLanguage);
            }

            if ($missingCount === 0) {
                $this->line('0 missing keys found...aborting');
                continue;
            }

            $this->line($strLen.' characters will be translated');

            if (! $this->confirm('Continue?', true)) {
                continue;
            }

            $bar = $this->output->createProgressBar($missingCount);
            $bar->start();

            foreach ($targetLanguages as $targetLanguage) {
                $missing = $this->autoTranslator->getMissingTranslations($targetLanguage);

                $translated = $this->autoTranslator->translate($targetLanguage, $missing, function () use ($bar) {
                    $bar->advance();
                });

                $this->autoTranslator->fillLanguageFiles($targetLanguage, $translated);
            }

            $bar->finish();

            $this->info("\nTranslated ".$missingCount.' missing language keys.');
        }
    }
}
