<?php

namespace Carlin\LaravelTranslationCollector\Contracts;

interface ExternalApiClientInterface
{
    /**
     * 新增待翻译文本到外部系统
     *
     * @param array $translations 翻译数据
     * @return array
     */
    public function addTranslations(array $translations): array;

    /**
     * 初始化项目翻译
     *
     * @param array $translations 翻译数据
     * @return array
     */
    public function initTranslations(array $translations): array;

    /**
     * 从外部系统获取所有翻译
     *
     * @param array $options 查询选项
     * @return array
     */
    public function getTranslations(array $options = []): array;

    /**
     * 批量上传翻译
     *
     * @param array $translations 翻译数据
     * @param int $batchSize 批量大小
     * @return array
     */
    public function batchUpload(array $translations, int $batchSize = 100): array;

    /**
     * 检查外部API连接状态
     *
     * @return bool
     */
    public function checkConnection(): bool;

    /**
     * 设置API配置
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self;
}
