<?php

namespace App\Support;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class Setting
{
    const CACHE_KEY = 'admin_settings';

    private ?array $loadedSettings = null; // 请求内缓存
    private bool $loadAttempted = false;

    /**
     * 获取配置.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return Arr::get($this->loadedSettings ?? [], strtolower($key), $default);
    }

    /**
     * 设置配置信息.
     */
    public function set(string $key, mixed $value = null): bool
    {
        SettingModel::createOrUpdate(strtolower($key), $value);
        $this->flush();
        return true;
    }

    /**
     * 保存配置到数据库.
     */
    public function save(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate(strtolower($key), $value);
        }
        $this->flush();
        return true;
    }

    /**
     * 删除配置信息
     */
    public function remove(string $key): bool
    {
        SettingModel::where('name', $key)->delete();
        $this->flush();
        return true;
    }

    /**
     * 更新单个设置项
     */
    public function update(string $key, $value): bool
    {
        return $this->set($key, $value);
    }
    
    /**
     * 批量获取配置项
     */
    public function getBatch(array $keys): array
    {
        $this->load();
        $result = [];
        
        foreach ($keys as $index => $item) {
            $isNumericIndex = is_numeric($index);
            $key = strtolower($isNumericIndex ? $item : $index);
            $default = $isNumericIndex ? config('v2board.' . $item) : (config('v2board.' . $key) ?? $item);
            
            $result[$item] = Arr::get($this->loadedSettings ?? [], $key, $default);
        }
        
        return $result;
    }
    
    /**
     * 将所有设置转换为数组
     */
    public function toArray(): array
    {
        $this->load();
        return $this->loadedSettings ?? [];
    }

    /**
     * 加载配置到请求内缓存
     */
    private function load(): void
    {
        if ($this->loadAttempted) {
            return;
        }

        $this->loadAttempted = true;
        try {
            $settings = Cache::store('redis')->rememberForever(self::CACHE_KEY, $this->loadFromDatabase(...));
        } catch (\Throwable $e) {
            \Log::warning('Settings cache unavailable; reading from database', ['exception_type' => $e::class]);
            try {
                $settings = $this->loadFromDatabase();
            } catch (\Throwable $e) {
                \Log::error('Site settings unavailable', ['exception_type' => $e::class]);
                return;
            }
        }

        foreach ($settings as $key => $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $settings[$key] = $decoded;
                }
            }
        }
        $this->loadedSettings = $settings;
    }

    public function isAvailable(): bool
    {
        $this->load();
        return $this->loadedSettings !== null;
    }

    private function loadFromDatabase(): array
    {
        return array_change_key_case(SettingModel::pluck('value', 'name')->toArray(), CASE_LOWER);
    }

    /**
     * 清空缓存
     */
    private function flush(): void
    {
        $this->loadedSettings = null;
        $this->loadAttempted = false;
        Cache::store('redis')->forget(self::CACHE_KEY);
    }
}
