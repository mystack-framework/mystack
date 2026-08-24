<?php

/**
 * ============================================================================
 * Class: PHTM
 * Title: Timezone & Date Manager
 * ============================================================================
 * 
 * Comprehensive utilities for timezone configuration, date parsing, calculations, modifications, and localization formatting.
 * 
 * Features:
 * - Global timezone configuration.
 * - Date parsing and robust calculations.
 * - Time modification (add/sub days, months).
 * - Localized date and time formatting.
 * 
 * Usage Example:
 * ```php
 * PHTM::setZone('Asia/Dhaka');
 * $future = PHTM::addDays(PHTM::now(), 7);
 * echo PHTM::format($future, 'Y-m-d H:i:s');
 * ```
 * 
 * @package    MyStack
 * @author     Sakibur Rahman (@sakibweb)
 * @published  https://github.com/mystack-framework
 * @web        https://mystack-framework.github.io
 * @license    Apache License 2.0
 * ============================================================================
 */



class PHTM {

    protected static $timezone = 'UTC';

    /**
     * Set the default timezone.
     * 
     * @param string $timezone Timezone identifier (e.g., 'Asia/Dhaka')
     */
    public static function setZone($timezone) {
        $timezone = (string) $timezone;
        if (!in_array($timezone, timezone_identifiers_list(), true) || !date_default_timezone_set($timezone)) {
            throw new InvalidArgumentException('Invalid timezone identifier');
        }
        self::$timezone = $timezone;
    }

    /**
     * Get the current default timezone.
     * 
     * @return string Current timezone identifier
     */
    public static function getZone() {
        return self::$timezone;
    }

    /**
     * Get the current date and time in the specified format.
     * 
     * @param string $format Date/time format (default: 'Y-m-d H:i:s')
     * @return string Formatted current date/time
     */
    public static function getTime($format = 'Y-m-d H:i:s') {
        return date($format);
    }

    /**
     * Format a given timestamp according to the specified format.
     * 
     * @param mixed $timestamp Unix timestamp or date/time string
     * @param string $format Date/time format (default: 'Y-m-d H:i:s')
     * @return string Formatted date/time
     */
    public static function setTime($timestamp, $format = 'Y-m-d H:i:s') {
        date_default_timezone_set(self::$timezone);
        
        if (is_int($timestamp)) {
            return date($format, $timestamp);
        } elseif (is_string($timestamp)) {
            $timestamp = strtotime($timestamp);
            if ($timestamp === false) {
                throw new InvalidArgumentException('Invalid date/time string');
            }
            return date($format, $timestamp);
        } else {
            throw new InvalidArgumentException('Invalid timestamp format');
        }
    }

    /**    
     * Calculate the difference between two date/times.    
     *     
     * @param string $datetime1 First date/time string    
     * @param string $datetime2 Second date/time string    
     * @return array Associative array containing the difference in years, months, days, hours, minutes, and seconds    
     */    
    public static function calculate($datetime1, $datetime2 = null) {    
        if ($datetime2 === null) {    
            $datetime2 = self::getTime();    
        }    
            
        $date1 = new DateTime($datetime1);    
        $date2 = new DateTime($datetime2);    
        $interval = $date1->diff($date2);    
        
        $isExpired = $date1 < $date2;

        return [    
            'years'   => $interval->y,    
            'months'  => $interval->m,    
            'days'    => $interval->d,    
            'hours'   => $interval->h,    
            'minutes' => $interval->i,    
            'seconds' => $interval->s,    
            'expire'  => !$isExpired  
        ];    
    }

    /**
     * Modify a date/time string by adding or subtracting a specified interval.
     * 
     * @param string $datetime Base date/time string
     * @param string $modifier Date/time modifier string (e.g., '+7d', '-1y')
     * @param string $format Date/time format (default: 'Y-m-d H:i:s')
     * @return string Calculated date/time string
     */
    public static function modify($datetime, $modifier, $format = 'Y-m-d H:i:s') {
        // Extract the first character from the modifier string to determine the sign
        if (substr($modifier, 0, 1) === '+' || substr($modifier, 0, 1) === '-') {
            $first_sign = substr($modifier, 0, 1);
        } else {
            $first_sign = "+";
        }

        // Normalize the modifier string
        $modifier = self::normalizeModifier($modifier, $first_sign);

        // Create a DateTime object with the base datetime
        $date = new DateTime($datetime);

        // Check if the modifier starts with '+' or '-'
        if ($first_sign === '+' || $first_sign === '-') {
            if ($date->modify($modifier) === false) {
                throw new InvalidArgumentException('Invalid date/time modifier');
            }
        } else {
            // Default behavior if no explicit sign is provided (considering '+' by default)
            if ($date->modify('+' . $modifier) === false) {
                throw new InvalidArgumentException('Invalid date/time modifier');
            }
        }

        return $date->format($format);
    }

    /**
     * Normalize various modifier formats to a consistent format.
     * 
     * @param string $modifier Raw modifier string
     * @param string $first_sign First character sign from the modifier
     * @return string Normalized modifier string
     */
    protected static function normalizeModifier($modifier, $first_sign) {
        // Remove extra spaces and normalize white spaces
        $modifier = trim(preg_replace('/\s+/', ' ', $modifier));
        $parts = explode(' ', $modifier);
        $normalizedParts = [];

        foreach ($parts as $part) {
            preg_match('/(\+|\-)?(\d+)([a-zA-Z]+)/', $part, $matches);
            if ($matches) {
                $sign = $matches[1] ?? '';
                if ($sign === "") {
                    $sign = $first_sign;
                }
                $number = $matches[2];
                $unit = strtolower($matches[3]);

                switch ($unit) {
                    case 'd':
                    case 'day':
                    case 'days':
                        $unit = 'day';
                        break;
                    case 'm':
                    case 'month':
                    case 'months':
                        $unit = 'month';
                        break;
                    case 'y':
                    case 'year':
                    case 'years':
                        $unit = 'year';
                        break;
                    case 'h':
                    case 'hour':
                    case 'hours':
                        $unit = 'hour';
                        break;
                    case 'i':
                    case 'minute':
                    case 'minutes':
                        $unit = 'minute';
                        break;
                    case 's':
                    case 'second':
                    case 'seconds':
                        $unit = 'second';
                        break;
                    default:
                        throw new InvalidArgumentException("Unknown unit: $unit");
                }
                
                $normalizedParts[] = "$sign$number $unit";
            }
        }

        return implode(' ', $normalizedParts);
    }

    /**
     * Change the format of a date/time string.
     * 
     * @param string $datetime Input date/time string
     * @param string $outputFormat Desired output format
     * @return string Formatted date/time string
     */
    public static function format($datetime, $outputFormat) {
        return self::convertTimeFormat($datetime, $outputFormat, false);
    }

    /**
     * Convert a date/time string from 24-hour format to 12-hour format with a custom output format.
     * 
     * @param string $datetime Date/time string in 24-hour format
     * @param string $outputFormat Desired output format (default: 'g:i:s A')
     * @return string Date/time string in 12-hour format
     */
    public static function to12h($datetime, $outputFormat = 'g:i:s A') {
        return self::convertTimeFormat($datetime, $outputFormat, true);
    }

    /**
     * Convert a date/time string from 12-hour format to 24-hour format with a custom output format.
     * 
     * @param string $datetime Date/time string in 12-hour format
     * @param string $outputFormat Desired output format (default: 'H:i:s')
     * @return string Date/time string in 24-hour format
     */
    public static function to24h($datetime, $outputFormat = 'H:i:s') {
        return self::convertTimeFormat($datetime, $outputFormat, false);
    }

    /**
     * Helper function to convert time formats.
     * 
     * @param string $datetime Input date/time string
     * @param string $outputFormat Desired output format
     * @param bool $to12h Convert to 12-hour format if true, otherwise to 24-hour format
     * @return string Converted date/time string
     */
    protected static function convertTimeFormat($datetime, $outputFormat, $to12h) {
        $inputFormat = self::detectDateTimeFormat($datetime);

        $date = DateTime::createFromFormat($inputFormat, $datetime);

        if ($date === false) {
            throw new InvalidArgumentException("Invalid date/time format: $datetime");
        }

        if ($to12h) {
            $outputFormat = str_replace('H', 'g', str_replace('h', 'g', $outputFormat));
        }

        return $date->format($outputFormat);
    }

    /**
     * Detect the format of a given date/time string.
     * 
     * @param string $datetime Date/time string in various formats
     * @return string Detected date/time format
     */
    protected static function detectDateTimeFormat($datetime) {
        $formats = [
            // Standard formats with various separators and combinations
            'Y-m-d H:i:s',
            'Y/m/d H:i:s',
            'Y_m_d H:i:s',
            'Y m d H:i:s',
            'Y-m-d h:i:s A',
            'Y/m/d h:i:s A',
            'Y_m_d h:i:s A',
            'Y m d h:i:s A',
            'H:i:s', // Time only in 24-hour format
            'h:i:s A', // Time only in 12-hour format with AM/PM
            'h:i:sa', // Time only in 12-hour format with AM/PM (without spaces)
            'd-m-Y H:i:s',
            'd/m/Y H:i:s',
            'd_m_Y H:i:s',
            'd m Y H:i:s',
            'd-m-Y h:i:s A',
            'd/m/Y h:i:s A',
            'd_m_Y h:i:s A',
            'd m Y h:i:s A',
            'YmdHis', // Compact format without separators
            'dmYHis', // Compact European date format without separators
            // Date only formats
            'Y-m-d',
            'Y/m/d',
            'Y_m_d',
            'Y m d',
            'd-m-Y',
            'd/m/Y',
            'd_m_Y',
            'd m Y',
            // Other variations
            'M d, Y H:i:s', // Month name, day, year with time
            'd M Y H:i:s', // Day, month name, year with time
            'd F Y H:i:s', // Day, full month name, year with time
            'Y-F-d H:i:s', // Year, full month name, day with time
            'd F Y', // Day, full month name, year (date only)
            'Y F d', // Year, full month name, day (date only)
            'Y M d', // Year, abbreviated month name, day (date only)
            'D, M d, Y H:i:s', // Day of the week, month name, day, year with time
            'Y M d D H:i:s', // Year, abbreviated month name, day, day of the week with time
            'D, d M Y H:i:s', // Day of the week, day, month name, year with time
            // Additional formats with AM/PM
            'd-M-Y h:i:s A',
            'd/M/Y h:i:s A',
            'd_M_Y h:i:s A',
            'd m Y h:i:s A',
            'M d, Y h:i:s A',
            'd M Y h:i:s A',
            'd F Y h:i:s A',
            'Y-F-d h:i:s A',
            'd F Y A', // Day, full month name, year with AM/PM (date only)
            'Y F d A', // Year, full month name, day with AM/PM (date only)
            'Y M d A', // Year, abbreviated month name, day with AM/PM (date only)
            'D, M d, Y h:i:s A', // Day of the week, month name, day, year with AM/PM
            'Y M d D h:i:s A', // Year, abbreviated month name, day, day of the week with AM/PM
            'D, d M Y h:i:s A', // Day of the week, day, month name, year with AM/PM
            // Formats with lowercase am/pm
            'Y-m-d h:i:s a',
            'Y/m/d h:i:s a',
            'Y_m_d h:i:s a',
            'Y m d h:i:s a',
            'd-m-Y h:i:s a',
            'd/m/Y h:i:s a',
            'd_m_Y h:i:s a',
            'd m Y h:i:s a',
            'd-M-Y h:i:s a',
            'd/M/Y h:i:s a',
            'd_M_Y h:i:s a',
            'd m Y h:i:s a',
            'M d, Y h:i:s a',
            'd M Y h:i:s a',
            'd F Y h:i:s a',
            'Y-F-d h:i:s a',
            'd F Y a', // Day, full month name, year with am/pm (date only)
            'Y F d a', // Year, full month name, day with am/pm (date only)
            'Y M d a', // Year, abbreviated month name, day with am/pm (date only)
            'D, M d, Y h:i:s a', // Day of the week, month name, day, year with am/pm
            'Y M d D h:i:s a', // Year, abbreviated month name, day, day of the week with am/pm
            'D, d M Y h:i:s a', // Day of the week, day, month name, year with am/pm
        ];    

        foreach ($formats as $format) {
            $dateObj = DateTime::createFromFormat($format, $datetime);
            if ($dateObj !== false && $dateObj->format($format) === $datetime) {
                return $format;
            }
        }

        throw new InvalidArgumentException("Invalid date/time format: $datetime");
    }

    /**
     * Normalize a date/time string to a DateTime object.
     * 
     * @param string $datetime Date/time string in various formats
     * @return DateTime Normalized DateTime object
     */
    protected static function normalizeDateTime($datetime) {
        $format = self::detectDateTimeFormat($datetime);
        return DateTime::createFromFormat($format, $datetime);
    }
}
?>
