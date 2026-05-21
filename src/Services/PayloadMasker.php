<?php

namespace TraceReplay\Services;

class PayloadMasker
{
    /**
     * Recursively mask sensitive fields in an array.
     */
    public function mask(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];
        $fields = $this->fields();

        foreach ($data as $key => $value) {
            if (\in_array($this->normalizeKey((string) $key), $fields, true)) {
                $result[$key] = '********';
            } elseif (is_array($value)) {
                $result[$key] = $this->mask($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    protected function fields(): array
    {
        return array_map(
            fn (string $field) => $this->normalizeKey($field),
            config('trace-replay.mask_fields', [
                'password', 'password_confirmation', 'token',
                'api_key', 'authorization', 'secret', 'credit_card',
            ])
        );
    }

    public function maskUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $parts['query'] = http_build_query(
                $this->mask($query),
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        } elseif (! isset($parts['pass'])) {
            return $url;
        }

        return $this->buildUrl($parts);
    }

    protected function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($key)) ?? strtolower($key);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    protected function buildUrl(array $parts): string
    {
        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'].'://';
        }

        if (isset($parts['user'])) {
            $url .= $parts['user'];

            if (isset($parts['pass'])) {
                $url .= ':********';
            }

            $url .= '@';
        }

        $url .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '';

        if (array_key_exists('query', $parts) && $parts['query'] !== '') {
            $url .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}
