<?php

namespace TraceReplay\Services;

class PayloadMasker
{
    /** @var array<string> */
    protected array $fields;

    public function __construct()
    {
        $this->fields = array_map(
            'strtolower',
            config('tracereplay.mask_fields', [
                'password', 'password_confirmation', 'token',
                'api_key', 'authorization', 'secret', 'credit_card',
            ])
        );
    }

    /**
     * Recursively mask sensitive fields in an array.
     *
     * @param  mixed $data
     * @return mixed
     */
    public function mask(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (\in_array(strtolower((string) $key), $this->fields, true)) {
                $result[$key] = '********';
            } elseif (is_array($value)) {
                $result[$key] = $this->mask($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}

