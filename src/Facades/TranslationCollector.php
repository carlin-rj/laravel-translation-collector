<?php

namespace Carlin\LaravelTranslationCollector\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array collect(array $options = [])
 * @method static array scanPaths($paths, array $options = [])
 * @method static array scanModules($modules, array $options = [])
 * @method static array analyzeDifferences(array $collected, array $existing)
 * @method static array getStatistics()
 * @method static self setOptions(array $options)
 */
class TranslationCollector extends Facade
{
    /**
     * 获取组件的注册名称
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'translation-collector';
    }
}
