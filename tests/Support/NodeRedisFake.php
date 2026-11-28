<?php

namespace Tests\Support;

class NodeRedisFake
{
    public array $values = [];
    public array $hashes = [];
    public array $published = [];
    public int $subscribers = 1;
    public ?\Closure $beforePublish = null;

    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function setex(string $key, int $ttl, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function hget(string $key, string $field): string|false
    {
        return $this->hashes[$key][$field] ?? false;
    }

    public function hgetall(string $key): array
    {
        return $this->hashes[config('database.redis.options.prefix', '') . $key] ?? [];
    }

    public function eval(string $script, int $keys, mixed ...$args): mixed
    {
        if ($keys === 3) {
            [$counter, $delivered, $pending, $node, $revision, $channel, $message] = $args;
            if ($message !== '' && $this->beforePublish) {
                $callback = $this->beforePublish;
                $this->beforePublish = null;
                $callback();
            }
            if (($this->values[$counter] ?? 0) > $revision) {
                return 0;
            }
            $this->values[$counter] = $revision;
            if ($this->hget($delivered, $node) !== $revision) {
                $this->hashes[$pending][$node] = $revision;
            }
            if ($message !== '' && $this->subscribers > 0) {
                $this->published[] = $message;
                return $this->subscribers;
            }
            return 0;
        }

        if ($keys === 2 && str_starts_with($args[0], 'node:state_delivered:')) {
            [$delivered, $pending, $node, $revision] = $args;
            if (($this->hashes[$delivered][$node] ?? 0) <= $revision) {
                $this->hashes[$delivered][$node] = $revision;
            }
            if (($this->hashes[$pending][$node] ?? 0) <= $revision) {
                unset($this->hashes[$pending][$node]);
            }
            return null;
        }

        if ($keys === 0 && str_contains($script, 'local devices = cjson.decode')) {
            [$prefix, $node, $json, $complete, $timestamp] = $args;
            $affected = [];
            if ($complete === '1') {
                foreach ($this->hashes as $key => &$fields) {
                    if (!str_starts_with($key, $prefix)) {
                        continue;
                    }
                    foreach (array_keys($fields) as $field) {
                        if (str_starts_with($field, $node . ':')) {
                            unset($fields[$field]);
                            $affected[] = substr($key, strlen($prefix));
                        }
                    }
                }
                unset($fields);
            }
            foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR) as $uid => $ips) {
                foreach ($ips as $ip) {
                    $this->hashes[$prefix . $uid]["{$node}:{$ip}"] = $timestamp;
                }
                $affected[] = (string) $uid;
            }

            return array_unique($affected);
        }

        if ($keys === 2 && $args[0] === 'device:push_pending_nodes') {
            return null;
        }

        throw new \LogicException('Unexpected Redis script in node test');
    }

    public function publish(string $channel, string $message): int
    {
        $this->published[] = $message;
        return $this->subscribers;
    }
}
