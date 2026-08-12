<?php

namespace Modules\AiChatPanel\Services\Tools;

/**
 * A small JSON Schema validator covering the subset that tool parameters
 * actually use: object/string/integer/number/boolean/array, required, enum,
 * minimum/maximum, minLength/maxLength, and items.
 *
 * Written by hand rather than pulled in as a dependency: the surface is tiny,
 * and adding a composer package to a module that installs by unzipping into
 * Modules/ is a support burden out of all proportion to the benefit.
 *
 * It coerces where a model realistically errs — "7" for an integer, "true" for
 * a boolean — because rejecting those produces a pointless extra round trip.
 * It does not coerce anything ambiguous.
 */
class SchemaValidator
{
    /** @var string[] */
    protected $errors = [];

    /**
     * Validate and coerce.
     *
     * @param mixed $value
     * @param array $schema
     *
     * @return array [bool $ok, mixed $coerced, string[] $errors]
     */
    public function validate($value, array $schema)
    {
        $this->errors = [];

        $coerced = $this->walk($value, $schema, '');

        return [empty($this->errors), $coerced, $this->errors];
    }

    /**
     * @param mixed  $value
     * @param array  $schema
     * @param string $path
     *
     * @return mixed
     */
    protected function walk($value, $schema, $path)
    {
        if (!is_array($schema) || !$schema) {
            return $value;
        }

        $type = isset($schema['type']) ? $schema['type'] : null;

        // A union type passes if any branch does; try them in order.
        if (is_array($type)) {
            foreach ($type as $candidate) {
                $branch = $schema;
                $branch['type'] = $candidate;

                $probe = new self();
                list($ok, $coerced) = $probe->validate($value, $branch);

                if ($ok) {
                    return $coerced;
                }
            }

            $this->error($path, 'does not match any of the allowed types ('.implode(', ', $type).')');

            return $value;
        }

        switch ($type) {
            case 'object':
                return $this->walkObject($value, $schema, $path);

            case 'array':
                return $this->walkArray($value, $schema, $path);

            case 'string':
                return $this->walkString($value, $schema, $path);

            case 'integer':
                return $this->walkNumber($value, $schema, $path, true);

            case 'number':
                return $this->walkNumber($value, $schema, $path, false);

            case 'boolean':
                return $this->walkBoolean($value, $schema, $path);

            default:
                return $this->checkEnum($value, $schema, $path);
        }
    }

    /**
     * @return array
     */
    protected function walkObject($value, $schema, $path)
    {
        if ($value === null) {
            $value = [];
        }

        if (!is_array($value)) {
            $this->error($path, 'must be an object');

            return $value;
        }

        $properties = isset($schema['properties']) ? $schema['properties'] : [];

        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }

        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];

        foreach ($required as $name) {
            // An explicit null for a required field is as missing as absent.
            if (!array_key_exists($name, $value) || $value[$name] === null) {
                $this->error($this->join($path, $name), 'is required');
            }
        }

        $result = [];

        foreach ($value as $name => $item) {
            if (!isset($properties[$name])) {
                // Unknown properties are dropped rather than rejected: models
                // add stray fields often, and it is not worth a round trip.
                continue;
            }

            if ($item === null) {
                continue;
            }

            $result[$name] = $this->walk($item, (array) $properties[$name], $this->join($path, $name));
        }

        return $result;
    }

    /**
     * @return array
     */
    protected function walkArray($value, $schema, $path)
    {
        // A model asked for a list will sometimes send a bare scalar.
        if ($value !== null && !is_array($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            $this->error($path, 'must be an array');

            return [];
        }

        $items = isset($schema['items']) ? (array) $schema['items'] : [];

        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            $value = array_slice($value, 0, (int) $schema['maxItems']);
        }

        $result = [];

        foreach (array_values($value) as $i => $item) {
            $result[] = $items ? $this->walk($item, $items, $path.'['.$i.']') : $item;
        }

        return $result;
    }

    /**
     * @return string
     */
    protected function walkString($value, $schema, $path)
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            $this->error($path, 'must be a string');

            return '';
        }

        if (isset($schema['maxLength']) && mb_strlen($value) > (int) $schema['maxLength']) {
            $value = mb_substr($value, 0, (int) $schema['maxLength']);
        }

        if (isset($schema['minLength']) && mb_strlen($value) < (int) $schema['minLength']) {
            $this->error($path, 'must be at least '.(int) $schema['minLength'].' characters');
        }

        return $this->checkEnum($value, $schema, $path);
    }

    /**
     * @return int|float
     */
    protected function walkNumber($value, $schema, $path, $integer)
    {
        if (is_string($value) && is_numeric(trim($value))) {
            $value = trim($value) + 0;
        }

        if (!is_int($value) && !is_float($value)) {
            $this->error($path, $integer ? 'must be an integer' : 'must be a number');

            return 0;
        }

        if ($integer) {
            if (is_float($value) && floor($value) != $value) {
                $this->error($path, 'must be an integer');

                return (int) $value;
            }

            $value = (int) $value;
        }

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $this->error($path, 'must be at least '.$schema['minimum']);
        }

        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $this->error($path, 'must be at most '.$schema['maximum']);
        }

        return $this->checkEnum($value, $schema, $path);
    }

    /**
     * @return bool
     */
    protected function walkBoolean($value, $schema, $path)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));

            if (in_array($lower, ['true', '1', 'yes'])) {
                return true;
            }

            if (in_array($lower, ['false', '0', 'no'])) {
                return false;
            }
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return (bool) $value;
        }

        $this->error($path, 'must be true or false');

        return false;
    }

    /**
     * @return mixed
     */
    protected function checkEnum($value, $schema, $path)
    {
        if (empty($schema['enum']) || !is_array($schema['enum'])) {
            return $value;
        }

        foreach ($schema['enum'] as $allowed) {
            if ($allowed === $value) {
                return $value;
            }
        }

        // Case-insensitive rescue for string enums: models get the casing wrong
        // far more often than they get the value wrong.
        if (is_string($value)) {
            foreach ($schema['enum'] as $allowed) {
                if (is_string($allowed) && strcasecmp($allowed, $value) === 0) {
                    return $allowed;
                }
            }
        }

        $this->error($path, 'must be one of: '.implode(', ', array_map(function ($v) {
            return is_scalar($v) ? (string) $v : gettype($v);
        }, $schema['enum'])));

        return $value;
    }

    /**
     * @return void
     */
    protected function error($path, $message)
    {
        $this->errors[] = ($path === '' ? 'value' : $path).' '.$message;
    }

    /**
     * @return string
     */
    protected function join($path, $name)
    {
        return $path === '' ? $name : $path.'.'.$name;
    }
}
