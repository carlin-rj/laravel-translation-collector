<?php

namespace Carlin\LaravelTranslationCollector\Contracts;

interface TranslationCollectorInterface
{
    /**
     * 收集项目中的所有翻译文本
     *
     * @param array $options 收集选项
     * @return array
     */
    public function collect(array $options = []): array;

    /**
     * 扫描指定路径的翻译文本
     *
     * @param string|array $paths 扫描路径
     * @param array $options 扫描选项
     * @return array
     */
    public function scanPaths($paths, array $options = []): array;

    /**
     * 扫描指定模块的翻译文本
     *
     * @param string|array $modules 模块名称
     * @param array $options 扫描选项
     * @return array
     */
    public function scanModules($modules, array $options = []): array;

    /**
     * 分析翻译差异
     *
     * @param array $collected 收集到的翻译
     * @param array $existing 现有的翻译
     * @return array
     */
    public function analyzeDifferences(array $collected, array $existing): array;

    /**
     * 获取收集统计信息
     *
     * @return array
     */
    public function getStatistics(): array;

    /**
     * 设置收集选项
     *
     * @param array $options
     * @return self
     */
    public function setOptions(array $options): self;
}
