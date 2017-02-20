<?php

namespace SilverStripe\ORM\FieldType;

use Doctrine\DBAL\Types\Type;
use IntlDateFormatter;
use InvalidArgumentException;
use NumberFormatter;
use SilverStripe\Forms\DateField;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Represents a date field.
 * Dates should be stored using ISO 8601 formatted date (y-MM-dd).
 * Alternatively you can set a timestamp that is evaluated through
 * PHP's built-in date() function according to your system locale.
 *
 * Example definition via {@link DataObject::$db}:
 * <code>
 * static $db = array(
 *  "Expires" => "Date",
 * );
 * </code>
 *
 * Date formats all follow CLDR standard format codes
 * @link http://userguide.icu-project.org/formatparse/datetime
 */
class DBDate extends DBField
{
    /**
     * Standard ISO format string for date in CLDR standard format
     */
    const ISO_DATE = 'y-MM-dd';

    public function getDBOptions()
    {
        return parent::getDBOptions() + [
            'notnull' => false,
        ];
    }

    public function setValue($value, $record = null, $markChanged = true)
    {
        $value = $this->parseDate($value);
        if ($value === false) {
            throw new InvalidArgumentException(
                "Invalid date: '$value'. Use " . self::ISO_DATE . " to prevent this error."
            );
        }
        $this->value = $value;
        return $this;
    }

    /**
     * Parse timestamp or iso8601-ish date into standard iso8601 format
     *
     * @param mixed $value
     * @return string|null|false Formatted date, null if empty but valid, or false if invalid
     */
    protected function parseDate($value)
    {
        // Skip empty values
        if (empty($value) && !is_numeric($value)) {
            return null;
        }

        // Determine value to parse
        if (is_array($value)) {
            $source = $value; // parse array
        } elseif (is_numeric($value)) {
            $source = $value; // parse timestamp
        } else {
            // Convert US date -> iso, fix y2k, etc
            $value = $this->fixInputDate($value);
            if (is_null($value)) {
                return null;
            }
            $source = strtotime($value); // convert string to timestamp
        }
        if ($value === false) {
            return false;
        }

        // Format as iso8601
        $formatter = $this->getFormatter();
        $formatter->setPattern($this->getISOFormat());
        return $formatter->format($source);
    }

    /**
     * Returns the standard localised medium date
     *
     * @return string
     */
    public function Nice()
    {
        if (!$this->value) {
            return null;
        }
        $formatter = $this->getFormatter();
        return $formatter->format($this->getTimestamp());
    }

    /**
     * Returns the year from the given date
     *
     * @return string
     */
    public function Year()
    {
        return $this->Format('y');
    }

    /**
     * Returns the day of the week
     *
     * @return string
     */
    public function DayOfWeek()
    {
        return $this->Format('cccc');
    }

    /**
     * Returns a full textual representation of a month, such as January.
     *
     * @return string
     */
    public function Month()
    {
        return $this->Format('LLLL');
    }

    /**
     * Returns the short version of the month such as Jan
     *
     * @return string
     */
    public function ShortMonth()
    {
        return $this->Format('LLL');
    }

    /**
     * Returns the day of the month.
     *
     * @param bool $includeOrdinal Include ordinal suffix to day, e.g. "th" or "rd"
     * @return string
     */
    public function DayOfMonth($includeOrdinal = false)
    {
        $number = $this->Format('d');
        if ($includeOrdinal && $number) {
            $formatter = NumberFormatter::create(i18n::get_locale(), NumberFormatter::ORDINAL);
            return $formatter->format((int)$number);
        }
        return $number;
    }

    /**
     * Returns the date in the localised short format
     *
     * @return string
     */
    public function Short()
    {
        if (!$this->value) {
            return null;
        }
        $formatter = $this->getFormatter(IntlDateFormatter::SHORT);
        return $formatter->format($this->getTimestamp());
    }

    /**
     * Returns the date in the localised long format
     *
     * @return string
     */
    public function Long()
    {
        if (!$this->value) {
            return null;
        }
        $formatter = $this->getFormatter(IntlDateFormatter::LONG);
        return $formatter->format($this->getTimestamp());
    }

    /**
     * Returns the date in the localised full format
     *
     * @return string
     */
    public function Full()
    {
        if (!$this->value) {
            return null;
        }
        $formatter = $this->getFormatter(IntlDateFormatter::FULL);
        return $formatter->format($this->getTimestamp());
    }

    /**
     * Get date formatter
     *
     * @param int $dateLength
     * @param int $timeLength
     * @return IntlDateFormatter
     */
    public function getFormatter($dateLength = IntlDateFormatter::MEDIUM, $timeLength = IntlDateFormatter::NONE)
    {
        return new IntlDateFormatter(i18n::get_locale(), $dateLength, $timeLength);
    }

    /**
     * Get standard ISO date format string
     *
     * @return string
     */
    public function getISOFormat()
    {
        return self::ISO_DATE;
    }

    /**
     * Return the date using a particular formatting string. Use {o} to include an ordinal representation
     * for the day of the month ("1st", "2nd", "3rd" etc)
     *
     * @param string $format Format code string. See http://userguide.icu-project.org/formatparse/datetime
     * @return string The date in the requested format
     */
    public function Format($format)
    {
        if (!$this->value) {
            return null;
        }

        // Replace {o} with ordinal representation of day of the month
        if (strpos($format, '{o}') !== false) {
            $format = str_replace('{o}', "'{$this->DayOfMonth(true)}'", $format);
        }

        $formatter = $this->getFormatter();
        $formatter->setPattern($format);
        return $formatter->format($this->getTimestamp());
    }

    /**
     * Get unix timestamp for this date
     *
     * @return int
     */
    public function getTimestamp()
    {
        if ($this->value) {
            return strtotime($this->value);
        }
        return 0;
    }

    /**
     * Return a date formatted as per a CMS user's settings.
     *
     * @param Member $member
     * @return boolean | string A date formatted as per user-defined settings.
     */
    public function FormatFromSettings($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        // Fall back to nice
        if (!$member) {
            return $this->Nice();
        }

        // Get user format
        $format = $member->getDateFormat();
        return $this->Format($format);
    }

    /**
     * Return a string in the form "12 - 16 Sept" or "12 Aug - 16 Sept"
     *
     * @param DBDate $otherDateObj Another date object specifying the end of the range
     * @param bool $includeOrdinals Include ordinal suffix to day, e.g. "th" or "rd"
     * @return string
     */
    public function RangeString($otherDateObj, $includeOrdinals = false)
    {
        $d1 = $this->DayOfMonth($includeOrdinals);
        $d2 = $otherDateObj->DayOfMonth($includeOrdinals);
        $m1 = $this->ShortMonth();
        $m2 = $otherDateObj->ShortMonth();
        $y1 = $this->Year();
        $y2 = $otherDateObj->Year();

        if ($y1 != $y2) {
            return "$d1 $m1 $y1 - $d2 $m2 $y2";
        } elseif ($m1 != $m2) {
            return "$d1 $m1 - $d2 $m2 $y1";
        } else {
            return "$d1 - $d2 $m1 $y1";
        }
    }

    /**
     * Return string in RFC822 format
     *
     * @return string
     */
    public function Rfc822()
    {
        if ($this->value) {
            return date('r', $this->getTimestamp());
        }
        return null;
    }

    /**
     * Return date in RFC2822 format
     *
     * @return string
     */
    public function Rfc2822()
    {
        return $this->Format('y-MM-dd HH:mm:ss');
    }

    /**
     * Date in RFC3339 format
     *
     * @return string
     */
    public function Rfc3339()
    {
        return date('c', $this->getTimestamp());
    }

    /**
     * Returns the number of seconds/minutes/hours/days or months since the timestamp.
     *
     * @param boolean $includeSeconds Show seconds, or just round to "less than a minute".
     * @param int $significance Minimum significant value of X for "X units ago" to display
     * @return string
     */
    public function Ago($includeSeconds = true, $significance = 2)
    {
        if (!$this->value) {
            return null;
        }
        $timestamp = $this->getTimestamp();
        $now = DBDatetime::now()->getTimestamp();
        if ($timestamp <= $now) {
            return _t(
                'SilverStripe\\ORM\\FieldType\\DBDate.TIMEDIFFAGO',
                "{difference} ago",
                'Natural language time difference, e.g. 2 hours ago',
                array('difference' => $this->TimeDiff($includeSeconds, $significance))
            );
        } else {
            return _t(
                'SilverStripe\\ORM\\FieldType\\DBDate.TIMEDIFFIN',
                "in {difference}",
                'Natural language time difference, e.g. in 2 hours',
                array('difference' => $this->TimeDiff($includeSeconds, $significance))
            );
        }
    }

    /**
     * @param boolean $includeSeconds Show seconds, or just round to "less than a minute".
     * @param int $significance Minimum significant value of X for "X units ago" to display
     * @return string
     */
    public function TimeDiff($includeSeconds = true, $significance = 2)
    {
        if (!$this->value) {
            return false;
        }

        $now = DBDatetime::now()->getTimestamp();
        $time = $this->getTimestamp();
        $ago = abs($time - $now);
        if ($ago < 60 && !$includeSeconds) {
            return _t('SilverStripe\\ORM\\FieldType\\DBDate.LessThanMinuteAgo', 'less than a minute');
        } elseif ($ago < $significance * 60 && $includeSeconds) {
            return $this->TimeDiffIn('seconds');
        } elseif ($ago < $significance * 3600) {
            return $this->TimeDiffIn('minutes');
        } elseif ($ago < $significance * 86400) {
            return $this->TimeDiffIn('hours');
        } elseif ($ago < $significance * 86400 * 30) {
            return $this->TimeDiffIn('days');
        } elseif ($ago < $significance * 86400 * 365) {
            return $this->TimeDiffIn('months');
        } else {
            return $this->TimeDiffIn('years');
        }
    }

    /**
     * Gets the time difference, but always returns it in a certain format
     *
     * @param string $format The format, could be one of these:
     * 'seconds', 'minutes', 'hours', 'days', 'months', 'years'.
     * @return string The resulting formatted period
     */
    public function TimeDiffIn($format)
    {
        if (!$this->value) {
            return null;
        }

        $now = DBDatetime::now()->getTimestamp();
        $time = $this->getTimestamp();
        $ago = abs($time - $now);
        switch ($format) {
            case "seconds":
                $span = $ago;
                return _t(
                    __CLASS__ . '.SECONDS_SHORT_PLURALS',
                    '{count} sec|{count} secs',
                    ['count' => $span]
                );

            case "minutes":
                $span = round($ago/60);
                return _t(
                    __CLASS__ . '.MINUTES_SHORT_PLURALS',
                    '{count} min|{count} mins',
                    ['count' => $span]
                );

            case "hours":
                $span = round($ago/3600);
                return _t(
                    __CLASS__ . '.HOURS_SHORT_PLURALS',
                    '{count} hour|{count} hours',
                    ['count' => $span]
                );

            case "days":
                $span = round($ago/86400);
                return _t(
                    __CLASS__ . '.DAYS_SHORT_PLURALS',
                    '{count} day|{count} days',
                    ['count' => $span]
                );

            case "months":
                $span = round($ago/86400/30);
                return _t(
                    __CLASS__ . '.MONTHS_SHORT_PLURALS',
                    '{count} month|{count} months',
                    ['count' => $span]
                );

            case "years":
                $span = round($ago/86400/365);
                return _t(
                    __CLASS__ . '.YEARS_SHORT_PLURALS',
                    '{count} year|{count} years',
                    ['count' => $span]
                );

            default:
                throw new \InvalidArgumentException("Invalid format $format");
        }
    }

    public function getDBType()
    {
        return Type::DATE;
    }

    /**
     * Returns true if date is in the past.
     * @return boolean
     */
    public function InPast()
    {
        return strtotime($this->value) < DBDatetime::now()->getTimestamp();
    }

    /**
     * Returns true if date is in the future.
     * @return boolean
     */
    public function InFuture()
    {
        return strtotime($this->value) > DBDatetime::now()->getTimestamp();
    }

    /**
     * Returns true if date is today.
     * @return boolean
     */
    public function IsToday()
    {
        return $this->Format(self::ISO_DATE) === DBDatetime::now()->Format(self::ISO_DATE);
    }

    /**
     * Returns a date suitable for insertion into a URL and use by the system.
     *
     * @return string
     */
    public function URLDate()
    {
        return rawurlencode($this->Format(self::ISO_DATE));
    }

    public function scaffoldFormField($title = null, $params = null)
    {
        $field = DateField::create($this->name, $title);
        $field->setHTML5(true);

        return $field;
    }

    /**
     * Fix non-iso dates
     *
     * @param string $value
     * @return string
     */
    protected function fixInputDate($value)
    {
        // split
        list($year, $month, $day, $time) = $this->explodeDateString($value);

        if ((int)$year === 0 && (int)$month === 0 && (int)$day === 0) {
            return null;
        }
        // Validate date
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(
                "Invalid date: '$value'. Use " . self::ISO_DATE . " to prevent this error."
            );
        }

        // Convert to y-m-d
        return sprintf('%d-%02d-%02d%s', $year, $month, $day, $time);
    }

    /**
     * Attempt to split date string into year, month, day, and timestamp components.
     *
     * @param string $value
     * @return array
     */
    protected function explodeDateString($value)
    {
        // split on known delimiters (. / -)
        if (!preg_match(
            '#^(?<first>\\d+)[-/\\.](?<second>\\d+)[-/\\.](?<third>\\d+)(?<time>.*)$#',
            $value,
            $matches
        )) {
            throw new InvalidArgumentException(
                "Invalid date: '$value'. Use " . self::ISO_DATE . " to prevent this error."
            );
        }

        $parts = [
            $matches['first'],
            $matches['second'],
            $matches['third']
        ];
        // Flip d-m-y to y-m-d
        if ($parts[0] < 1000 && $parts[2] > 1000) {
            $parts = array_reverse($parts);
        }
        if ($parts[0] < 1000 && (int)$parts[0] !== 0) {
            throw new InvalidArgumentException(
                "Invalid date: '$value'. Use " . self::ISO_DATE . " to prevent this error."
            );
        }
        $parts[] = $matches['time'];
        return $parts;
    }
}
