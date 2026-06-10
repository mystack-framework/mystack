<?php

/**
 * ============================================================================
 * Class: PHVD
 * Author: Sakibur Rahman (@sakibweb)
 * ============================================================================
 * 
 * This class is a core component of the MyStack framework.
 * It provides essential functionalities tailored for high performance and security.
 */


// Helper exception for internal use
class PhvdInternalException extends \Exception
{
}

// You can create this helper class or use the string format directly.
class PhvdRule
{
    public static function unique(string $table, string $column, $except = null): string
    {
        return "unique:{$table},{$column}" . ($except ? ",{$except}" : '');
    }
    public static function exists(string $table, string $column): string
    {
        return "exists:{$table},{$column}";
    }
}

class PHVD
{
    // --- Rule to Method Mapping ---
    private const RULE_MAP = [
        'required' => 'validateRequired',
        'required_if' => 'validateRequiredIf',
        'used' => 'validateUsed',
        'unused' => 'validateUnused',
        'filled' => 'validateFilled',
        'string' => 'validateString',
        'numeric' => 'validateNumeric',
        'integer' => 'validateInteger',
        'float' => 'validateFloat',
        'boolean' => 'validateBoolean',
        'array' => 'validateArray',
        'json' => 'validateJson',
        'min' => 'validateMin',
        'max' => 'validateMax',
        'between' => 'validateBetween',
        'size' => 'validateSize',
        'gt' => 'validateGt',
        'gte' => 'validateGte',
        'lt' => 'validateLt',
        'lte' => 'validateLte',
        'alpha' => 'validateAlpha',
        'alpha_num' => 'validateAlphaNum',
        'alpha_dash' => 'validateAlphaDash',
        'alpha_space' => 'validateAlphaSpace',
        'digits' => 'validateDigits',
        'digits_between' => 'validateDigitsBetween',
        'username' => 'validateUsername',
        'base64' => 'validateBase64',
        'ulid' => 'validateUlid',
        'required_with' => 'validateRequiredWith',
        'required_without' => 'validateRequiredWithout',
        'email' => 'validateEmail',
        'url' => 'validateUrl',
        'active_url' => 'validateActiveUrl',
        'ip' => 'validateIp',
        'ipv4' => 'validateIpv4',
        'ipv6' => 'validateIpv6',
        'domain' => 'validateDomain',
        'password' => 'validatePasswordLevel',
        'mobile' => 'validateMobile',
        'phone' => 'validateMobile',
        'slug' => 'validateSlug',
        'color' => 'validateColor',
        'word' => 'validateWordCount',
        'count' => 'validateItemCount',
        'card' => 'validateCreditCard',
        'encrypt' => 'validateEncrypted',
        'lat_long' => 'validateLatLong',
        'extension' => 'validateExtension',
        'ext' => 'validateExtension',
        'currency' => 'validateCurrency',
        'price' => 'validatePrice',
        'mac_address' => 'validateMacAddress',
        'uuid' => 'validateUuid',
        'regex' => 'validateRegex',
        'not_regex' => 'validateNotRegex',
        'starts_with' => 'validateStartsWith',
        'ends_with' => 'validateEndsWith',
        'date' => 'validateDate',
        'date_format' => 'validateDateFormat',
        'before' => 'validateBefore',
        'after' => 'validateAfter',
        'before_or_equal' => 'validateBeforeOrEqual',
        'after_or_equal' => 'validateAfterOrEqual',
        'timezone' => 'validateTimezone',
        'same' => 'validateSame',
        'different' => 'validateDifferent',
        'confirmed' => 'validateConfirmed',
        'unique' => 'validateUnique',
        'exist' => 'validateExist',
        'in' => 'validateIn',
        'not_in' => 'validateNotIn',
        'distinct' => 'validateDistinct',
        'file' => 'validateFile',
        'mime' => 'validateMime',
        'max_size' => 'validateMaxSize',
        'image' => 'validateImage',
        'dimensions' => 'validateDimensions',
        'accepted' => 'validateAccepted',
        'safe' => 'validateSafe',
        'age' => 'validateAge',
        'expire' => 'validateExpire',
        'active' => 'validateActive',
        'no_space' => 'validateNoSpace',
        'pattern' => 'validatePattern',
        'start_num' => 'validateStartWithNumber',
        'card_format' => 'validateCardFormat',
    ];

    // --- Character Sets for 'exist' Rule ---
    private const CHAR_SETS = [
        'number' => ['\d', 'number'],
        'numbers' => ['\d', 'number'],
        'digit' => ['\d', 'digit'],
        'digits' => ['\d', 'digit'],
        'int' => ['\d', 'number'],
        '0-9' => ['\d', 'number'],
        'a-z' => ['a-z', 'lowercase letter'],
        'smaller-alphavet' => ['a-z', 'lowercase letter'],
        'lowercase' => ['a-z', 'lowercase letter', 'lowercase letters'],
        'A-Z' => ['A-Z', 'uppercase letter'],
        'higher-alphavet' => ['A-Z', 'uppercase letter'],
        'uppercase' => ['A-Z', 'uppercase letter', 'uppercase letters'],
        'alphavet' => ['a-zA-Z', 'letter'],
        'letter' => ['a-zA-Z', 'letter'],
        'letters' => ['a-zA-Z', 'letters'],
        'symbol' => ['\W_', 'symbol'],
        'symbols' => ['\W_', 'symbols'],
        'sign' => ['\W_', 'symbol'],
        'signs' => ['\W_', 'symbol'],
    ];

    // --- Extensive Default Error Message Library ---
    private const MESSAGES = [
        'accepted' => 'The :field must be accepted.',
        'safe' => 'The :field contains unsafe or malicious content.',
        'active_url' => 'The :field is not a valid, active URL.',
        'after' => 'The :field must be a date after :date.',
        'after_or_equal' => 'The :field must be a date after or equal to :date.',
        'alpha' => 'The :field may only contain letters.',
        'alpha_dash' => 'The :field may only contain letters, numbers, dashes, and underscores.',
        'alpha_num' => 'The :field may only contain letters and numbers.',
        'alpha_space' => 'The :field may only contain letters and spaces.',
        'username' => 'The :field must be a valid username (letters, numbers, underscores).',
        'base64' => 'The :field must be a valid Base64 string.',
        'ulid' => 'The :field must be a valid ULID.',
        'required_with' => 'The :field field is required when :other is present.',
        'required_without' => 'The :field field is required when :other is not present.',
        'array' => 'The :field must be an array.',
        'before' => 'The :field must be a date before :date.',
        'before_or_equal' => 'The :field must be a date before or equal to :date.',
        'between' => 'The :field must be between :min and :max.',
        'boolean' => 'The :field must be true or false.',
        'confirmed' => 'The :field confirmation does not match.',
        'date' => 'The :field is not a valid date.',
        'date_format' => 'The :field does not match the format :format.',
        'different' => 'The :field and :other must be different.',
        'digits' => 'The :field must be :value digits.',
        'digits_between' => 'The :field must be between :min and :max digits.',
        'dimensions' => 'The :field image has invalid dimensions.',
        'distinct' => 'The :field field has a duplicate value.',
        'email' => 'The :field must be a valid email address.',
        'ends_with' => 'The :field must end with one of the following: :values.',
        'exists' => 'The selected :field is invalid.',
        'file' => 'The :field must be a file.',
        'filled' => 'The :field field must have a value.',
        'gt' => 'The :field must be greater than :value.',
        'gte' => 'The :field must be greater than or equal to :value.',
        'image' => 'The :field must be an image.',
        'in' => 'The selected :field is invalid.',
        'integer' => 'The :field must be an integer.',
        'ip' => 'The :field must be a valid IP address.',
        'ipv4' => 'The :field must be a valid IPv4 address.',
        'ipv6' => 'The :field must be a valid IPv6 address.',
        'domain' => 'The :field must be from allowed domains: :values.',
        'password' => 'The :field password strength must be :value.',
        'mobile' => 'The :field number format or length is invalid.',
        'phone' => 'The :field number format is invalid.',
        'slug' => 'The :field must be a valid slug (a-z, 0-9, dashes).',
        'color' => 'The :field must be a valid :value color code.',
        'word' => 'The :field word count is invalid.',
        'count' => 'The :field item count must be :value.',
        'card' => 'The :field must be a valid credit card number.',
        'encrypt' => 'The :field must be a valid encrypted string.',
        'lat_long' => 'The :field must be a valid latitude and longitude (e.g., 23.68,90.37).',
        'extension' => 'The :field must have a valid extension: :values.',
        'ext' => 'The :field extension must be one of: :values.',
        'currency' => 'The :field is not a valid currency code.',
        'price' => 'The :field is not a valid price format.',
        'json' => 'The :field must be a valid JSON string.',
        'lt' => 'The :field must be less than :value.',
        'lte' => 'The :field must be less than or equal to :value.',
        'mac_address' => 'The :field must be a valid MAC address.',
        'min' => 'The :field must be at least :value characters.',
        'max' => 'The :field may not be greater than :value characters.',
        'max_size' => 'The :field may not be greater than :value kilobytes.',
        'mime' => 'The :field must be a file of type: :values.',
        'not_in' => 'The selected :field is invalid.',
        'not_regex' => 'The :field format is invalid.',
        'numeric' => 'The :field must be a number.',
        'password_strong' => 'The :field is not strong enough.',
        'regex' => 'The :field format is invalid.',
        'required' => 'The :field field is required.',
        'required_if' => 'The :field field is required when :other is :value.',
        'used' => 'The :field not found in our records.',
        'unused' => 'The :field has already been taken.',
        'same' => 'The :field and :other must match.',
        'size' => 'The :field must be :value.',
        'starts_with' => 'The :field must start with one of the following: :values.',
        'string' => 'The :field must be a string.',
        'timezone' => 'The :field must be a valid timezone.',
        'unique' => 'The :field has already been taken.',
        'url' => 'The :field must be a valid URL.',
        'uuid' => 'The :field must be a valid UUID.',
        'exist_db' => 'The selected :field does not exist in our records.',
        'exist_char' => 'The :field is not valid.',
        'exist_missing' => 'The :field must contain at least one :missing.',
        'exist_min' => 'The :field must contain at least :min :missing.',
        'exist_char_predefined' => 'The :field must contain at least one :description.',
        'exist_char_custom' => 'The :field must contain at least one of these characters: :values.',
        'exist' => 'The :field is missing required :missing.',
        'exist_detailed' => 'The :field must contain at least :missing.',
        'age' => 'The :field must be at least :value years old.',
        'expire' => 'The :field date has already expired.',
        'active' => 'The :field date is not currently active.',
        'no_space' => 'The :field must not contain any spaces.',
        'pattern' => 'The :field format does not match the required pattern.',
        'start_num' => 'The :field must start with the number: :values.',
        'card_format' => 'The :field must be a valid formatted card number.',
    ];

    public static function check(array $rules, array|bool|null $data = null, bool $debug = false): array
    {
        // --- Flexible Debug Parameter Handling ---
        if (is_bool($data)) {
            $debug = $data;
            $data = null;
        }

        if ($debug) {
            echo "<pre style='background-color: #222; color: #eee; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 14px; line-height: 1.6;'>";
            echo "<strong>PHVD DEBUGGER: Validation process started.</strong>\n\n";
        }

        $tasks = [];
        // --- Auto-detect mode and normalize validation tasks ---
        if ($data !== null) { // Separate Data Mode (e.g., check($rules, $data))
            if ($debug)
                echo "<strong>Mode: Separate Data & Rules</strong>\n";
            $data = array_merge($data, $_FILES); // Include file uploads
            foreach ($rules as $field_name => $config) {
                $path = $config[0] ?? $field_name;
                $rule_string = $config[1] ?? '';

                $found_paths = self::expandWildcardPath($path, $data);
                foreach ($found_paths as $concrete_path => $value) {
                    $tasks[$concrete_path] = ['value' => $value, 'rules' => $rule_string, 'original_field' => $field_name];
                }
            }
        } else { // Embedded Data Mode (e.g., check(['Field' => [$value, 'rules']]))
            if ($debug)
                echo "<strong>Mode: Embedded Data</strong>\n";
            foreach ($rules as $field_name => $config) {
                $tasks[$field_name] = ['value' => $config[0] ?? null, 'rules' => $config[1] ?? '', 'original_field' => $field_name];
            }
        }

        if ($debug) {
            echo "<strong>1. Normalized Validation Tasks:</strong> (" . count($tasks) . " total)\n";
            print_r($tasks);
        }

        $total_rules = 0;
        $passed_rules = 0;
        $errors = [];
        $validated_data = [];

        // --- Perform validation on the normalized tasks ---
        foreach ($tasks as $field_path => $task) {
            $value = $task['value'];
            $field_rules = is_array($task['rules']) ? $task['rules'] : explode('|', $task['rules']);
            $original_field = $task['original_field'];

            if ($debug)
                echo "\n<hr><strong>Validating Field: '{$original_field}'</strong> (Path: '{$field_path}')\n";
            if ($debug)
                echo "   - Value: " . (is_array($value) ? print_r($value, true) : htmlspecialchars((string) $value, ENT_QUOTES)) . "\n";
            if ($debug)
                echo "   - Rules: " . implode('|', $field_rules) . "\n";

            if (in_array('nullable', $field_rules) && self::isEmpty($value)) {
                if ($debug)
                    echo "   <span style='color: #00ffc5;'>- Rule 'nullable' matched. Skipping further validation.</span>\n";
                $count = count($field_rules);
                $total_rules += $count;
                $passed_rules += $count;
                $validated_data[$field_path] = $value;
                continue;
            }

            $field_has_error = false;
            foreach ($field_rules as $rule) {
                if (empty($rule) || $rule === 'nullable')
                    continue;
                $total_rules++;
                [$rule_name, $params] = self::parseRule($rule);

                if ($debug)
                    echo "   - Checking rule: <strong>'{$rule_name}'</strong> with params: " . json_encode($params) . "\n";

                if (!isset(self::RULE_MAP[$rule_name])) {
                    $error_msg = "Rule '{$rule_name}' is not a valid rule.";
                    if ($debug)
                        echo "   <span style='color: #ff5555;'>   - <strong>STATUS: FAILED.</strong> " . $error_msg . "</span>\n";
                    $errors[$field_path] = $error_msg;
                    $field_has_error = true;
                    break;
                }

                $method_name = self::RULE_MAP[$rule_name];
                if ($debug)
                    echo "   - Mapping to method: <strong>{$method_name}()</strong>\n";

                try {
                    $reflection = new \ReflectionMethod(self::class, $method_name);
                    $args = [$value, $params];
                    if ($reflection->getNumberOfParameters() > 2)
                        $args[] = $data ?? $rules; // Pass full context

                    if ($debug)
                        echo "   - Executing {$method_name}() ...\n";
                    $is_valid = call_user_func_array([self::class, $method_name], $args);

                    if ($is_valid) {
                        $passed_rules++;
                        if ($debug)
                            echo "   <span style='color: #50fa7b;'>   - <strong>STATUS: PASSED.</strong></span>\n";
                    } else {
                        $error_msg = self::generateErrorMessage($original_field, $rule_name, $params, $value);
                        $errors[$field_path] = $error_msg;
                        $field_has_error = true;
                        if ($debug)
                            echo "   <span style='color: #ff5555;'>   - <strong>STATUS: FAILED.</strong> Error: \"{$error_msg}\"</span>\n";
                        break;
                    }
                } catch (\Throwable $e) {
                    $error_msg = ($e instanceof PhvdInternalException) ? $e->getMessage() : "Rule '{$rule_name}' caused an error: " . $e->getMessage();
                    $errors[$field_path] = "Validation error: " . $error_msg;
                    $field_has_error = true;
                    if ($debug)
                        echo "   <span style='color: #ffb86c;'>   - <strong>EXCEPTION CAUGHT!</strong> " . $error_msg . "</span>\n";
                    break;
                }
            }
            if (!$field_has_error) {
                // ... (Type casting logic can be added here if needed) ...
                $validated_data[$field_path] = $value;
            }
        }

        // --- Final Response Generation ---
        $is_success = empty($errors);
        $score = ($total_rules > 0) ? (int) (($passed_rules / $total_rules) * 100) : 100;

        $response = ['result' => $is_success, 'status' => $is_success, 'score' => $score];
        if ($is_success) {
            $response['message'] = 'Validation successful.';
            $response['validated'] = $validated_data;
        } else {
            $response['message'] = self::joinErrorMessages(array_values($errors));
            $response['errors'] = $errors;
        }

        if ($debug) {
            echo "\n<hr><strong>Validation Finished.</strong>\n";
            echo "   - Total Rules: {$total_rules}\n";
            echo "   - Passed Rules: {$passed_rules}\n";
            echo "   - Final Score: {$score}%\n";
            echo "   - Final Status: " . ($is_success ? "<span style='color: #50fa7b;'>SUCCESS</span>" : "<span style='color: #ff5555;'>FAILED</span>") . "\n";
            echo "<strong>Final Response Array:</strong>\n";
            print_r($response);
            echo "</pre>";
        }

        return $response;
    }

    private static function joinErrorMessages(array $errors): string
    {
        $count = count($errors);
        if ($count === 0) {
            return '';
        }

        $cleaned_errors = array_map(function ($message) {
            $message = rtrim(trim($message), '.!?');
            return $message;
        }, $errors);

        $first_error = array_shift($cleaned_errors);
        $first_error = ucfirst($first_error);

        if (empty($cleaned_errors)) {
            return $first_error . '.';
        }

        $remaining_errors = array_map(function ($message) {
            return lcfirst($message);
        }, $cleaned_errors);

        if (count($remaining_errors) === 1) {
            return $first_error . ' and ' . $remaining_errors[0] . '.';
        }

        $last_error = array_pop($remaining_errors);
        return $first_error . ', ' . implode(', ', $remaining_errors) . ', and ' . $last_error . '.';
    }

    private static function expandWildcardPath(string $path, array $data): array
    {
        // If there's no wildcard, just get the single value.
        if (!str_contains($path, '*')) {
            return [$path => self::data_get($data, $path)];
        }

        $paths = [];
        // Split the path at the first wildcard.
        $key_parts = explode('.*.', $path, 2);
        $prefix = $key_parts[0];
        $suffix = $key_parts[1] ?? null;

        // Get the array segment that the wildcard applies to.
        $items = self::data_get($data, $prefix);

        if (is_array($items)) {
            foreach ($items as $key => $item) {
                // If there is more path after the wildcard (e.g., '*.id')
                if ($suffix) {
                    // Recursively expand the rest of the path.
                    $sub_paths = self::expandWildcardPath($suffix, $item);
                    foreach ($sub_paths as $sub_path => $value) {
                        $paths["{$prefix}.{$key}.{$sub_path}"] = $value;
                    }
                } else {
                    // If the wildcard is at the end (e.g., 'products.*')
                    $paths["{$prefix}.{$key}"] = $item;
                }
            }
        }

        return $paths;
    }

    private static function data_get(array $data, string $path)
    {
        // If the key exists directly, return it.
        if (isset($data[$path])) {
            return $data[$path];
        }

        // Traverse the array using dot notation.
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return null; // Path not found
            }
        }

        return $data;
    }

    private static function isEmpty($value): bool
    {
        if (is_array($value) && isset($value['error']) && $value['error'] === UPLOAD_ERR_NO_FILE) {
            return true;
        }
        return is_null($value) || $value === '';
    }


    private static function parseRule(string $rule): array
    {
        $params = [];
        if (strpos($rule, ':') !== false) {
            [$rule_name, $param_string] = explode(':', $rule, 2);
            $params = explode(',', $param_string);
        } else {
            $rule_name = $rule;
        }
        return [$rule_name, $params];
    }

    private static function generateErrorMessage(string $field, string $rule, array $params, $value): string
    {
        $msg = '';

        // --- 1. SPECIAL CASE: 'exist' Rule Logic (Advanced) ---
        if ($rule === 'exist') {
            $p0 = strtolower($params[0] ?? '');
            if (isset(self::CHAR_SETS[$p0])) {
                return strtr(self::MESSAGES['exist_char_predefined'], [':field' => $field, ':description' => self::CHAR_SETS[$p0][1]]);
            } elseif (count($params) >= 2) {
                $msg = self::MESSAGES['exist_db'];
            } else {
                return strtr(self::MESSAGES['exist_char_custom'], [':field' => $field, ':values' => $params[0] ?? '']);
            }
        }

        // --- WORD COUNT SPECIFIC LOGIC (User Friendly) ---
        if ($rule === 'word') {
            $p = $params[0] ?? '0';
            $n = preg_replace('/\D/', '', $p); // Extract number
            if (str_starts_with($p, 'min-'))
                return "The $field must contain at least $n words.";
            if (str_starts_with($p, 'max-'))
                return "The $field must not exceed $n words.";
            return "The $field must contain exactly $n words.";
        }

        // --- 2. Load Base Message ---
        if (empty($msg)) {
            $msg = self::MESSAGES[$rule] ?? "The :field format is invalid.";
        }

        // --- 3. SMART CONTEXT AWARENESS (Auto-detect Unit for ANY size-related rule) ---
        // Check if the message contains size-related keywords
        if (preg_match('/(characters|items|kilobytes)/', $msg)) {
            $unit = 'characters'; // Default
            if (is_array($value) && isset($value['tmp_name'])) {
                $unit = 'kilobytes'; // File context
            } elseif (is_array($value)) {
                $unit = 'items'; // Array context
            } elseif (is_numeric($value) && !is_string($value)) {
                $unit = ''; // Numeric context (removes unit)
            }

            // Replace units intelligently (Handle 'characters long' before 'characters')
            $msg = str_replace(['characters long', 'characters', 'items', 'kilobytes'], $unit, $msg);
            $msg = str_replace('  ', ' ', $msg); // Fix double spaces if unit is empty
        }

        // --- 4. UNIVERSAL PARAMETER REPLACEMENT (Future Proof) ---
        $replace = [
            ':field' => $field,
            ':values' => implode(', ', $params),
            ':value' => $params[0] ?? '',
            // Common Aliases
            ':min' => $params[0] ?? '',
            ':max' => $params[1] ?? '',
            ':other' => $params[0] ?? '',
            ':format' => $params[0] ?? '',
        ];

        // Add :param0, :param1 support for custom extended rules
        foreach ($params as $k => $v) {
            $replace[":param{$k}"] = $v;
        }

        return trim(strtr($msg, $replace));
    }

    // --- The Ultimate Validation Rule Library ---

    // Presence & Type
    private static function validateRequired($value): bool
    {
        return !is_null($value) && $value !== '';
    }
    private static function validateUsed($v, $p): bool
    {
        if (!class_exists('PHDB'))
            return false;
        return !empty(PHDB::select($p[0], $p[1], [$p[1] => $v]));
    }
    private static function validateUnused($v, $p): bool
    {
        if (!class_exists('PHDB'))
            return false;
        $rows = PHDB::select($p[0], $p[1] . ($p[2] ?? '' ? ',' . ($p[3] ?? 'id') : ''), [$p[1] => $v]);
        if (empty($rows))
            return true;
        if (isset($p[2]) && isset($rows[0])) {
            $colId = $p[3] ?? 'id';
            return isset($rows[0][$colId]) && $rows[0][$colId] == $p[2];
        }
        return false;
    }
    private static function validateConfirmed($value, $params, $all_validations): bool
    {
        $current_field_name = null;
        foreach ($all_validations as $name => $config) {
            $rules = is_array($config[1]) ? $config[1] : explode('|', $config[1]);
            if ($config[0] === $value && (in_array('confirmed', $rules) || str_starts_with(current(array_filter($rules, fn($r) => str_starts_with($r, 'confirmed:'))), 'confirmed:'))) {
                $current_field_name = $name;
                break;
            }
        }
        if ($current_field_name === null)
            return false;
        $confirmation_field_name = '';
        if (!empty($params[0])) {
            $confirmation_field_name = $params[0];
        } else {
            $common_suffixes = ['_confirmation', '_confirm', '_retype', '_re_enter'];
            foreach ($common_suffixes as $suffix) {
                if (isset($all_validations[$current_field_name . $suffix])) {
                    $confirmation_field_name = $current_field_name . $suffix;
                    break;
                }
            }
        }
        if (empty($confirmation_field_name))
            return false;
        $confirmation_value = self::findValue($confirmation_field_name, $all_validations);
        return $value === $confirmation_value;
    }
    private static function validateFilled($value): bool
    {
        return !empty($value);
    }
    private static function validateString($value): bool
    {
        return is_string($value);
    }
    private static function validateNumeric($value): bool
    {
        return is_numeric($value);
    }
    private static function validateInteger($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
    private static function validateFloat($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }
    private static function validateBoolean($value): bool
    {
        return in_array($value, [true, false, 1, 0, '1', '0'], true);
    }
    private static function validateArray($value): bool
    {
        return is_array($value);
    }
    private static function validateJson($value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
    private static function validateExist($value, $params): bool
    {
        if (empty($value) || empty($params)) {
            return false;
        }
        $first_param = $params[0];
        if (count($params) >= 2) {
            if (!class_exists('PHDB')) {
                throw new PhvdInternalException("PHDB class not found for 'exist:db' rule.");
            }
            return PHDB::count($params[0], [$params[1] => $value]) > 0;
        }
        $char_set_key = strtolower($first_param);
        $regex = null;
        if (isset(self::CHAR_SETS[$char_set_key])) {
            $regex = '/[' . self::CHAR_SETS[$char_set_key][0] . ']/';
        } else {
            $regex = '/[' . preg_quote($first_param, '/') . ']/';
        }
        return (bool) preg_match($regex, $value);
    }

    // Size & Comparison
    private static function getValueSize($v)
    {
        if (is_int($v) || is_float($v))
            return $v;
        if (is_string($v))
            return mb_strlen($v);
        if (is_array($v) && isset($v['tmp_name']))
            return $v['size'] / 1024;
        if (is_array($v))
            return count($v);
        return 0;
    }
    private static function validateMin($value, $params): bool
    {
        return self::getValueSize($value) >= $params[0];
    }
    private static function validateMax($value, $params): bool
    {
        return self::getValueSize($value) <= $params[0];
    }
    private static function validateBetween($value, $params): bool
    {
        $size = self::getValueSize($value);
        return $size >= $params[0] && $size <= $params[1];
    }
    private static function validateSize($value, $params): bool
    {
        return self::getValueSize($value) == $params[0];
    }
    private static function validateGt($value, $params, $all): bool
    {
        return self::getValueSize($value) > self::getValueSize(self::findValue($params[0], $all));
    }
    private static function validateGte($value, $params, $all): bool
    {
        return self::getValueSize($value) >= self::getValueSize(self::findValue($params[0], $all));
    }
    private static function validateLt($value, $params, $all): bool
    {
        return self::getValueSize($value) < self::getValueSize(self::findValue($params[0], $all));
    }
    private static function validateLte($value, $params, $all): bool
    {
        return self::getValueSize($value) <= self::getValueSize(self::findValue($params[0], $all));
    }

    // String & Format
    private static function validateAlpha($value): bool
    {
        return preg_match('/^[a-zA-Z]+$/', $value);
    }
    private static function validateAlphaNum($value): bool
    {
        return preg_match('/^[a-zA-Z0-9]+$/', $value);
    }
    private static function validateAlphaDash($value): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $value);
    }
    private static function validateAlphaSpace($value): bool
    {
        return preg_match('/^[a-zA-Z\s]+$/', $value);
    } // Full Name এর জন্য
    private static function validateDigits($value, $params): bool
    {
        return preg_match('/^[0-9]{' . ($params[0] ?? 0) . '}$/', $value);
    } // OTP, PIN
    private static function validateDigitsBetween($value, $params): bool
    {
        return preg_match('/^[0-9]{' . ($params[0] ?? 0) . ',' . ($params[1] ?? 0) . '}$/', $value);
    }
    private static function validateUsername($value): bool
    {
        return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $value);
    } // Standard Username
    private static function validateBase64($value): bool
    {
        return preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $value) && base64_decode($value, true) !== false;
    }
    private static function validateUlid($value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $value);
    }
    private static function validateRequiredWith($value, $params, $tasks): bool
    {
        return !self::isEmpty(self::findValue($params[0] ?? '', $tasks)) ? self::validateRequired($value) : true;
    }
    private static function validateRequiredWithout($value, $params, $tasks): bool
    {
        return self::isEmpty(self::findValue($params[0] ?? '', $tasks)) ? self::validateRequired($value) : true;
    }
    private static function validateEmail($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL);
    }
    private static function validateUrl($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL);
    }
    private static function validateActiveUrl($value): bool
    {
        return checkdnsrr(parse_url($value, PHP_URL_HOST));
    }
    private static function validateIp($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP);
    }
    private static function validateIpv4($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }
    private static function validateIpv6($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }
    private static function validateDomain($v, $p): bool
    {
        return in_array(strtolower(preg_replace('/^www\./', '', filter_var($v, FILTER_VALIDATE_EMAIL) ? substr(strrchr($v, '@'), 1) : parse_url(preg_match('#^https?://#', $v) ? $v : "http://{$v}", PHP_URL_HOST))), array_map('strtolower', $p));
    }
    private static function validatePasswordLevel($v, $p): bool
    {
        $l = $p[0] ?? 'mid';
        if ($l === 'low')
            return strlen($v) >= 6;
        if ($l === 'mid')
            return strlen($v) >= 8 && preg_match('/[A-Z]/', $v) && preg_match('/[0-9]/', $v);
        if ($l === 'high')
            return strlen($v) >= 10 && preg_match('/[A-Z]/', $v) && preg_match('/[a-z]/', $v) && preg_match('/[0-9]/', $v) && preg_match('/[\W_]/', $v);
        return false;
    }
    private static function validateMobile($v, $p): bool
    {
        if (!preg_match('/^[\d\+\-\s]+$/', $v))
            return false;
        $len = strlen(preg_replace('/[\D]/', '', $v));
        if (empty($p) || $p[0] === 'x')
            return $len >= 8 && $len <= 15;
        if (count($p) > 1)
            return $len >= $p[0] && $len <= $p[1];
        return $len == $p[0];
    }
    private static function validateSlug($v): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v);
    }
    private static function validateColor($v, $p): bool
    {
        $t = strtolower($p[0] ?? 'hex');
        $patterns = ['hex' => '/^#?([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', 'rgb' => '/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/', 'hsl' => '/^hsl\(\s*(\d{1,3})\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*\)$/', 'cmyk' => '/^cmyk\(\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*\)$/', 'hsb' => '/^hsb\(\s*(\d{1,3})\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*\)$/', 'hsv' => '/^hsv\(\s*(\d{1,3})\s*,\s*(\d{1,3})%\s*,\s*(\d{1,3})%\s*\)$/', 'pantone' => '/^PMS\s+\d+(\s+[A-Z]+)?$/i'];
        return isset($patterns[$t]) ? (bool) preg_match($patterns[$t], $v) : false;
    }
    private static function validateWordCount($v, $p): bool
    {
        $count = str_word_count($v);
        $param = $p[0] ?? 'x';
        if (is_numeric($param))
            return $count == $param;
        if (str_starts_with($param, 'min-'))
            return $count >= (int) substr($param, 4);
        if (str_starts_with($param, 'max-'))
            return $count <= (int) substr($param, 4);
        return $count > 0;
    }
    private static function validateItemCount($v, $p): bool
    {
        $count = is_array($v) ? count($v) : (is_string($v) ? strlen($v) : 0);
        $param = $p[0] ?? 'x';
        if (is_numeric($param))
            return $count == $param;
        if (str_starts_with($param, 'min-'))
            return $count >= (int) substr($param, 4);
        if (str_starts_with($param, 'max-'))
            return $count <= (int) substr($param, 4);
        return $count > 0;
    }
    private static function validateCreditCard($v): bool
    {
        $v = preg_replace('/\D/', '', $v);
        $sum = 0;
        $len = strlen($v);
        $parity = $len % 2;
        for ($i = $len - 1; $i >= 0; $i--) {
            $digit = $v[$i];
            if (!$parity == ($i % 2)) {
                $digit *= 2;
                if ($digit > 9)
                    $digit -= 9;
            }
            $sum += $digit;
        }
        return ($sum % 10 == 0) && $len >= 13;
    }
    private static function validateEncrypted($v): bool
    {
        return base64_encode(base64_decode($v, true)) === $v;
    }
    private static function validateLatLong($v): bool
    {
        if (is_string($v))
            $v = explode(',', $v);
        if (!is_array($v) || count($v) !== 2)
            return false;
        return preg_match('/^[-]?(([0-8]?[0-9])\.(\d+))|(90(\.0+)?)$/', trim($v[0])) && preg_match('/^[-]?((((1[0-7][0-9])|([0-9]?[0-9]))\.(\d+))|180(\.0+)?)$/', trim($v[1]));
    }
    private static function validateExtension($v, $p): bool
    {
        if (is_array($v) && isset($v['name'])) {
            $ext = strtolower(pathinfo($v['name'], PATHINFO_EXTENSION));
            return in_array($ext, array_map('strtolower', $p));
        }
        return in_array(strtolower(pathinfo($v, PATHINFO_EXTENSION)), array_map('strtolower', $p));
    }
    private static function validateCurrency($v): bool
    {
        return preg_match('/^[A-Z]{3}$/', strtoupper($v));
    }
    private static function validatePrice($v): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $v);
    }
    private static function validateMacAddress($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_MAC);
    }
    private static function validateUuid($value): bool
    {
        return preg_match('/^[a-f\d]{8}(-[a-f\d]{4}){3}-[a-f\d]{12}$/i', $value);
    }
    private static function validateRegex($value, $params): bool
    {
        return preg_match($params[0], $value);
    }
    private static function validateNotRegex($value, $params): bool
    {
        return !preg_match($params[0], $value);
    }
    private static function validateStartsWith($value, $params): bool
    {
        foreach ($params as $p)
            if (str_starts_with($value, $p))
                return true;
        return false;
    }
    private static function validateEndsWith($value, $params): bool
    {
        foreach ($params as $p)
            if (str_ends_with($value, $p))
                return true;
        return false;
    }

    // Date & Time
    private static function validateDate($value): bool
    {
        return strtotime($value) !== false;
    }
    private static function validateDateFormat($value, $params): bool
    {
        $d = \DateTime::createFromFormat($params[0], $value);
        return $d && $d->format($params[0]) === $value;
    }
    private static function validateBefore($value, $params): bool
    {
        return strtotime($value) < strtotime($params[0]);
    }
    private static function validateAfter($value, $params): bool
    {
        return strtotime($value) > strtotime($params[0]);
    }
    private static function validateBeforeOrEqual($value, $params): bool
    {
        return strtotime($value) <= strtotime($params[0]);
    }
    private static function validateAfterOrEqual($value, $params): bool
    {
        return strtotime($value) >= strtotime($params[0]);
    }
    private static function validateTimezone($value): bool
    {
        return in_array($value, \DateTimeZone::listIdentifiers());
    }

    // Field Comparison
    private static function findValue(string $field_name, array $all_validations)
    {
        return $all_validations[$field_name][0] ?? null;
    }
    private static function validateSame($value, $params, $all): bool
    {
        return $value === self::findValue($params[0], $all);
    }
    private static function validateDifferent($value, $params, $all): bool
    {
        return $value !== self::findValue($params[0], $all);
    }

    // Database (requires PHDB)
    private static function validateUnique($value, $params): bool
    {
        if (!class_exists('PHDB'))
            throw new PhvdInternalException("PHDB class not found for 'unique' rule.");
        $table = $params[0];
        $column = $params[1];
        $except_id = $params[2] ?? null;
        $query = "SELECT COUNT(*) as count FROM `{$table}` WHERE `{$column}` = ?";
        $bindings = [$value];
        if ($except_id) {
            $except_column = $params[3] ?? 'id';
            $query .= " AND `{$except_column}` != ?";
            $bindings[] = $except_id;
        }
        $result = PHDB::query($query, $bindings);
        return ($result[0]['count'] ?? 1) === 0;
    }
    private static function validateExists($value, $params): bool
    {
        if (!class_exists('PHDB'))
            throw new PhvdInternalException("PHDB class not found for 'exists' rule.");
        $count = PHDB::count($params[0], [$params[1] => $value]);
        return $count > 0;
    }

    // Array & Content
    private static function validateIn($value, $params): bool
    {
        return in_array($value, $params);
    }
    private static function validateNotIn($value, $params): bool
    {
        return !in_array($value, $params);
    }
    private static function validateDistinct($value): bool
    {
        return is_array($value) && count($value) === count(array_unique($value));
    }

    // File Uploads
    private static function validateFile($value): bool
    {
        return is_array($value) && isset($value['tmp_name']) && is_uploaded_file($value['tmp_name']);
    }
    private static function validateMime($value, $params): bool
    {
        if (!self::validateFile($value))
            return false;
        $mime = mime_content_type($value['tmp_name']);
        foreach ($params as $type) {
            if (str_ends_with($mime, "/" . $type) || $mime === $type)
                return true;
        }
        return false;
    }
    private static function validateMaxSize($value, $params): bool
    {
        if (!self::validateFile($value))
            return false;
        return ($value['size'] / 1024) <= $params[0];
    }
    private static function validateImage($value): bool
    {
        return self::validateFile($value) && @getimagesize($value['tmp_name']) !== false;
    }
    private static function validateDimensions($value, $params): bool
    {
        if (!self::validateImage($value))
            return false;
        [$width, $height] = getimagesize($value['tmp_name']);
        $constraints = [];
        foreach ($params as $param)
            if (strpos($param, '=') !== false) {
                list($k, $v) = explode('=', $param);
                $constraints[$k] = $v;
            }
        if (isset($constraints['width']) && $width != $constraints['width'])
            return false;
        if (isset($constraints['height']) && $height != $constraints['height'])
            return false;
        if (isset($constraints['min_width']) && $width < $constraints['min_width'])
            return false;
        if (isset($constraints['min_height']) && $height < $constraints['min_height'])
            return false;
        if (isset($constraints['max_width']) && $width > $constraints['max_width'])
            return false;
        if (isset($constraints['max_height']) && $height > $constraints['max_height'])
            return false;
        if (isset($constraints['ratio'])) {
            $ratio_parts = explode('/', $constraints['ratio']);
            if (count($ratio_parts) === 2 && $ratio_parts[1] != 0)
                return abs(($width / $height) - ($ratio_parts[0] / $ratio_parts[1])) < 0.01;
        }
        return true;
    }


    // Conditional
    private static function validateAccepted($value): bool
    {
        return in_array($value, ['yes', 'on', 1, '1', true], true);
    }
    private static function validateRequiredIf($value, $params, $tasks): bool
    {
        $other_field_name = $params[0];
        $expected_other_value = $params[1];
        $actual_other_value = self::findValue($other_field_name, $tasks);
        if ($actual_other_value == $expected_other_value) {
            return self::validateRequired($value);
        }
        return true;
    }

    // Usage: age:18 (Checks if DOB is at least 18 years ago)
    private static function validateAge($value, $params): bool
    {
        $dob = strtotime($value);
        if (!$dob)
            return false;
        $age = (time() - $dob) / (365.25 * 24 * 60 * 60);
        return $age >= ($params[0] ?? 18);
    }

    // Usage: expire (Checks if a date is strictly in the past)
    private static function validateExpire($value): bool
    {
        $date = strtotime($value);
        return $date !== false && $date < time();
    }

    // Usage: active (Checks if current time is between start and end dates if array, or simply future/present if string)
    private static function validateActive($value): bool
    {
        $now = time();
        if (is_array($value) && count($value) >= 2) {
            $start = strtotime($value[0]);
            $end = strtotime($value[1]);
            return $start && $end && $now >= $start && $now <= $end;
        }
        $date = strtotime($value);
        return $date !== false && $date >= strtotime('today');
    }

    // Usage: no_space (Password, Username etc.)
    private static function validateNoSpace($value): bool
    {
        return strpos($value, ' ') === false;
    }

    // Usage: pattern:/^[a-z]+$/ (Custom Regex direct support)
    private static function validatePattern($value, $params): bool
    {
        $pattern = implode(',', $params);
        if (!str_starts_with($pattern, '/'))
            $pattern = '/' . $pattern . '/';
        return @preg_match($pattern, $value) === 1;
    }

    // Usage: start_num:01 (Strictly numeric start, good for BD phones)
    private static function validateStartWithNumber($value, $params): bool
    {
        foreach ($params as $prefix) {
            if (str_starts_with((string) $value, (string) $prefix))
                return true;
        }
        return false;
    }

    // Usage: card_format (Allows XXXX-XXXX-XXXX-XXXX or XXXX XXXX XXXX XXXX)
    // Usage: safe (Checks for SQL Injection, XSS, Directory Traversal, etc. Good for general form data and logs)
    private static function validateSafe($value): bool
    {
        if (!is_string($value)) return true; // Only validate strings

        // Null bytes
        if (strpos($value, "\0") !== false) return false;

        // Common malicious patterns
        $patterns = [
            // SQL Injection
            '/\bUNION\b\s+(?:ALL\s+)?\bSELECT\b/i',
            '/\bDROP\b\s+\b(?:TABLE|DATABASE)\b/i',
            '/\bDELETE\b\s+\bFROM\b/i',
            '/\bINSERT\b\s+\bINTO\b/i',
            '/\bUPDATE\b\s+.*\bSET\b/i',
            '/(?<!\w)OR\s+(?:\d+=\d+|\'\d+\'=\'\d+\'|"\d+"="\d+")/i', // Basic tautologies
            '/--\s*$/', // SQL comment at end
            '/\/\*.*\*\//U', // Block comment
            '/;\s*$/', // Trailing semicolon
            '/\bEXEC\b\s*\(/i', // Execution
            '/\bSLEEP\b\s*\(/i', // Time-based blind SQLi

            // XSS & HTML Injection
            '/<script\b[^>]*>.*?<\/script>/is',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/on\w+\s*=\s*(["\']).*?\1/i', // Inline JS events
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
            '/<applet\b/i',
            
            // Directory Traversal
            '/\.\.\//',
            '/\.\.\\/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function validateCardFormat($value): bool
    {
        return preg_match('/^(\d{4}[- ]?){3}\d{4,7}$/', $value);
    }
}