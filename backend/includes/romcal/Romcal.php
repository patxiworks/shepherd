<?php
/*
 * PHP port of ROMCAL 6 (the C program by Kenneth G. Bath, see NOTICE.txt in
 * this directory): computes the General Roman Calendar for one Gregorian
 * calendar year.
 *
 * The computation follows the C source step by step (init, christmas1, lent,
 * easter, advent, christmas2, proper, ordinary1, ordinary2) and, like the C
 * program, works with 0-based day-of-year numbers and integer arithmetic.
 * The fixed-date celebrations are read at run time from fixed.dat (same
 * format the C `mkfix` tool consumed), so editing that file needs no rebuild.
 *
 * Differences from the C program:
 *  - It returns rows instead of printing them. Everything the C text printer
 *    (printtxt.c) derived when printing is returned as fields: the class
 *    letter and extra notes embedded (tab separated) in a fixed.dat entry, the
 *    weekly Psalm 2 / Adoro te / Salve line and the votive Mass line.
 *  - xmas2.c wrote the Holy Family fields to cal[iday] (one past the end of the
 *    year) instead of cal[dec30] when Christmas falls on a Sunday, so Dec 30
 *    came out as a plain weekday named "Holy Family". Here Dec 30 is set
 *    properly as the Feast of the Holy Family.
 *  - Season is reported as Lent/Easter for the days the C code lumps together
 *    as "Paschal" (Ash Wednesday, Holy Week, Easter octave), and Jan 1 and
 *    Dec 25 are reported as Christmas (the C code leaves them "ordinary").
 */
final class Romcal
{
    // Days of the week (dow_t)
    private const SUNDAY_DOW = 0;
    private const MONDAY_DOW = 1;

    // Colors (color_t)
    private const NOCOLOR = 0;
    private const GREEN = 1;
    private const WHITE = 2;
    private const RED = 3;
    private const PURPLE = 4;
    private const ROSE = 5;

    // Ranks (rank_t); the numeric order is the precedence order
    private const WEEKDAY = 0;
    private const COMMEMORATION = 1;
    private const VOTIVE = 2;
    private const OPTIONAL = 3;
    private const MEMORIAL = 4;
    private const FEAST = 5;
    private const SUNDAY = 6;
    private const LORD = 7;
    private const ASHWED = 8;
    private const HOLYWEEK = 9;
    private const TRIDUUM = 10;
    private const SOLEMNITY = 11;

    // Seasons (season_t)
    private const ORDINARY = 0;
    private const ADVENT = 1;
    private const CHRISTMAS = 2;
    private const LENT = 3;
    private const EASTER = 4;
    private const PASCHAL = 5; // Ash Wed., Holy Week & Easter octave

    private const RANK_NAMES = [
        self::WEEKDAY => 'Weekday',
        self::COMMEMORATION => 'Commemoration',
        self::VOTIVE => 'Votive',
        self::OPTIONAL => 'Optional memorial',
        self::MEMORIAL => 'Memorial',
        self::FEAST => 'Feast',
        self::SUNDAY => 'Sunday',
        self::LORD => 'Feast of the Lord',
        self::ASHWED => 'Ash Wednesday',
        self::HOLYWEEK => 'Holy Week',
        self::TRIDUUM => 'Triduum',
        self::SOLEMNITY => 'Solemnity',
    ];

    private const COLOR_NAMES = [
        self::GREEN => 'Green',
        self::WHITE => 'White',
        self::RED => 'Red',
        self::PURPLE => 'Purple',
        self::ROSE => 'Rose',
    ];

    // fixed.dat rank letters (V = votive) and color letters
    private const FIXED_RANKS = [
        'O' => self::OPTIONAL,
        'M' => self::MEMORIAL,
        'F' => self::FEAST,
        'L' => self::LORD,
        'S' => self::SOLEMNITY,
        'V' => self::VOTIVE,
    ];
    private const FIXED_COLORS = [
        'G' => self::GREEN,
        'R' => self::RED,
        'W' => self::WHITE,
        'P' => self::PURPLE,
        '-' => self::NOCOLOR,
    ];
    private const MONTHS = [
        'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4,
        'MAY' => 5, 'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8,
        'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12,
    ];

    // What printtxt.c prints after the celebration, keyed by day of the week
    // (0 = Sunday). Votive lines only appear on days that are still green.
    private const VOTIVE_MASSES = [
        2 => 'Votive Mass of the Holy Angels',
        3 => 'Votive Mass of St. Joseph',
        4 => 'Votive Mass of the Holy Eucharist',
        5 => 'Votive Mass of the Holy Cross',
        6 => 'Common of the BVM',
    ];
    private const DEVOTIONS = [
        2 => 'Psalm 2',
        4 => 'Adoro te',
        6 => 'Salve',
    ];

    private int $year;
    private int $numdays;
    private int $edoy;    // day of year of Easter
    private int $cdoy;    // day of year of Christmas
    private int $sunmod;  // (day of year % 7) of the Sundays
    private bool $ccOnThursday;
    private bool $epOnJan6;
    private bool $asOnSunday;
    private bool $printOptionals;
    private array $fixed;
    private array $cal = [];
    private int $ibl = 0; // day of the Baptism of the Lord

    private function __construct(int $year, array $options, array $fixed)
    {
        $this->year = $year;
        $this->fixed = $fixed;
        $this->ccOnThursday = !empty($options['corpus_christi_on_thursday']);
        $this->epOnJan6 = !empty($options['epiphany_on_jan6']);
        $this->asOnSunday = !empty($options['ascension_on_sunday']);
        $this->printOptionals = $options['optional_memorials'] ?? true;

        $this->numdays = self::isLeapYear($year) ? 366 : 365;
        [$emonth, $eday] = self::easterDate($year);
        $this->edoy = self::doy($year, $emonth, $eday);
        $this->cdoy = self::doy($year, 12, 25);
        $this->sunmod = $this->edoy % 7;
    }

    /**
     * Computes the calendar of one Gregorian year (> 1582).
     *
     * $options (all optional): 'ascension_on_sunday', 'epiphany_on_jan6',
     * 'corpus_christi_on_thursday' (all default false, as in the C program)
     * and 'optional_memorials' (default true).
     *
     * Returns one row per day, in date order:
     *   date         'YYYY-MM-DD'
     *   celebration  name of the celebration
     *   class        'A'..'E' from fixed.dat (Sundays: 'C' when it has none), or null
     *   rank         'Solemnity', 'Feast', 'Memorial', 'Weekday', ...
     *   color        'Green', 'White', 'Red', 'Purple' or 'Rose'
     *   season       'Advent', 'Christmas', 'Ordinary Time', 'Lent' or 'Easter'
     *   notes        remaining fixed.dat text of the celebration, ' | ' separated, or null
     *   votive       votive Mass of the weekday (green weekdays Tue-Sat), or null
     *   devotion     'Psalm 2' (Tue), 'Adoro te' (Thu) or 'Salve' (Sat), or null
     */
    public static function generate(int $year, array $options = [], ?string $fixedPath = null): array
    {
        if ($year < 1583) {
            throw new InvalidArgumentException('Year must be in the Gregorian calendar (> 1582).');
        }
        $romcal = new self($year, $options, self::loadFixed($fixedPath ?? __DIR__ . '/fixed.dat'));
        return $romcal->run();
    }

    /** Easter Sunday of a year as [month, day] (eastdate.c). */
    public static function easterDate(int $year): array
    {
        $y = $year;
        $c = intdiv($y, 100);
        $n = $y - 19 * intdiv($y, 19);
        $k = intdiv($c - 17, 25);
        $i = $c - intdiv($c, 4) - intdiv($c - $k, 3) + 19 * $n + 15;
        $i = $i - 30 * intdiv($i, 30);
        $i = $i - intdiv($i, 28) * (1 - intdiv($i, 28) * intdiv(29, $i + 1) * intdiv(21 - $n, 11));
        $j = $y + intdiv($y, 4) + $i + 2 - $c + intdiv($c, 4);
        $j = $j - 7 * intdiv($j, 7);
        $l = $i - $j;
        $m = 3 + intdiv($l + 40, 44);
        $d = $l + 28 - 31 * intdiv($m, 4);
        return [$m, $d];
    }

    public static function isLeapYear(int $year): bool
    {
        return ($year % 400 === 0) || ($year % 100 !== 0 && $year % 4 === 0);
    }

    /** 0-based day of the year (doy.c). */
    public static function doy(int $year, int $month, int $day): int
    {
        $leap = [0, 31, 60, 91, 121, 152, 182, 213, 244, 274, 305, 335, 366];
        $common = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365];
        $table = self::isLeapYear($year) ? $leap : $common;
        return $table[$month - 1] + $day - 1;
    }

    /**
     * Reads fixed.dat: 'MONTH day RANK COLOR text'. In the text, tab-separated
     * pieces follow the name; a single letter A-E is the class, everything
     * else is kept as notes.
     */
    public static function loadFixed(string $path): array
    {
        static $cache = [];
        if (isset($cache[$path])) {
            return $cache[$path];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read $path");
        }
        $fixed = [];
        foreach ($lines as $n => $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || $line[0] === '#' || trim($line) === '') {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z]+)\s+(\d+)\s+(\S)\s+(\S)\s+(.*)$/', $line, $m)) {
                throw new RuntimeException("$path line " . ($n + 1) . ': cannot parse');
            }
            $month = self::MONTHS[strtoupper($m[1])] ?? null;
            $rank = self::FIXED_RANKS[$m[3]] ?? null;
            $color = self::FIXED_COLORS[$m[4]] ?? null;
            if ($month === null || $rank === null || $color === null) {
                throw new RuntimeException("$path line " . ($n + 1) . ': unknown month, rank or color');
            }
            $class = null;
            $name = null;
            $notes = [];
            foreach (explode("\t", $m[5]) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                if ($name === null) {
                    $name = $piece;
                } elseif ($class === null && preg_match('/^[A-E]$/', $piece)) {
                    $class = $piece;
                } else {
                    $notes[] = $piece;
                }
            }
            if ($name === null) {
                throw new RuntimeException("$path line " . ($n + 1) . ': no celebration name');
            }
            $fixed[] = [
                'month' => $month,
                'day' => (int) $m[2],
                'rank' => $rank,
                'color' => $color,
                'name' => $name,
                'class' => $class,
                'notes' => $notes ? implode(' | ', $notes) : null,
            ];
        }
        return $cache[$path] = $fixed;
    }

    // ---------------------------------------------------------------------

    private function run(): array
    {
        $this->init();
        $ibl = $this->ibl = $this->christmas1();  // Baptism of the Lord, end of the early Christmas season
        $iaw = $this->lent();        // Ash Wednesday
        $ips = $this->easter();      // Pentecost Sunday
        $iav = $this->advent();      // First Sunday of Advent
        $this->christmas2();
        $this->proper();
        $this->ordinary1($ibl, $iaw);
        $this->ordinary2($ips, $iav);
        return $this->rows();
    }

    private function dow(int $doy): int
    {
        return ($doy + 7 - $this->sunmod) % 7;
    }

    private function set(int $iday, string $celebration, int $season, int $color, ?int $rank = null): void
    {
        $this->cal[$iday]['celebration'] = $celebration;
        $this->cal[$iday]['season'] = $season;
        $this->cal[$iday]['color'] = $color;
        if ($rank !== null) {
            $this->cal[$iday]['rank'] = $rank;
        }
        $this->cal[$iday]['fixed'] = null;
    }

    /** Name like "Third Sunday of Lent" / "Monday of the Third Week of Lent" (gencel.c). */
    private static function gencel(int $season, int $weeknum, int $dow): string
    {
        static $seasons = ['Ordinary Time', 'Advent', 'Christmas', 'Lent', 'Easter'];
        static $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        static $nums = [
            '', 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth',
            'Tenth', 'Eleventh', 'Twelfth', 'Thirteenth', 'Fourteenth', 'Fifteenth', 'Sixteenth',
            'Seventeenth', 'Eighteenth', 'Nineteenth',
        ];
        if ($weeknum === 20) {
            $num = 'Twentieth';
        } elseif ($weeknum === 30) {
            $num = 'Thirtieth';
        } elseif ($weeknum > 30) {
            $num = 'Thirty-' . $nums[$weeknum % 10];
        } elseif ($weeknum > 20) {
            $num = 'Twenty-' . $nums[$weeknum % 10];
        } else {
            $num = $nums[$weeknum];
        }
        if ($dow === self::SUNDAY_DOW) {
            return $num . ' Sunday of ' . $seasons[$season];
        }
        return $days[$dow] . ' of the ' . $num . ' Week of ' . $seasons[$season];
    }

    // init.c
    private function init(): void
    {
        for ($iday = 0; $iday < $this->numdays; $iday++) {
            $this->cal[$iday] = [
                'rank' => ($iday % 7 === $this->sunmod) ? self::SUNDAY : self::WEEKDAY,
                'color' => self::GREEN,
                'season' => self::ORDINARY,
                'celebration' => null,
                'fixed' => null,
            ];
        }
    }

    // xmas1.c: returns the day of the Baptism of the Lord
    private function christmas1(): int
    {
        static $epbefore = [
            '', 'Monday before Epiphany', 'Tuesday before Epiphany', 'Wednesday before Epiphany',
            'Thursday before Epiphany', 'Friday before Epiphany', 'Saturday before Epiphany',
        ];
        static $epoctave = [
            '', 'Monday after Epiphany', 'Tuesday after Epiphany', 'Wednesday after Epiphany',
            'Thursday after Epiphany', 'Friday after Epiphany', 'Saturday after Epiphany',
        ];
        $ep = 'Epiphany of the Lord';
        $bl = 'Baptism of the Lord';

        if ($this->epOnJan6) {
            for ($iday = 1; $iday < 5; $iday++) {
                $dow = $this->dow($iday);
                $name = ($dow === self::SUNDAY_DOW) ? self::gencel(self::CHRISTMAS, 2, $dow) : $epbefore[$dow];
                $this->set($iday, $name, self::CHRISTMAS, self::WHITE);
            }
            $iep = 5;
            $this->set($iep, $ep, self::CHRISTMAS, self::WHITE, self::SOLEMNITY);
            $ibl = 12 - $this->dow(5);
            $this->set($ibl, $bl, self::CHRISTMAS, self::WHITE, self::LORD);
            for ($iday = $iep + 1; $iday < $ibl; $iday++) {
                $this->set($iday, $epoctave[$this->dow($iday)], self::CHRISTMAS, self::WHITE);
            }
            return $ibl;
        }

        $jan1 = $this->dow(0);
        $iep = 7 - $jan1;
        $ibl = ($jan1 === self::SUNDAY_DOW || $jan1 === self::MONDAY_DOW) ? $iep + 1 : $iep + 7;
        for ($iday = 1; $iday <= $ibl; $iday++) {
            $dow = $this->dow($iday);
            $this->cal[$iday]['season'] = self::CHRISTMAS;
            $this->cal[$iday]['color'] = self::WHITE;
            if ($iday < $iep) {
                $this->cal[$iday]['celebration'] = $epbefore[$dow];
            } elseif ($iday === $iep) {
                $this->cal[$iday]['celebration'] = $ep;
                $this->cal[$iday]['rank'] = self::SOLEMNITY;
            } elseif ($iday < $ibl) {
                $this->cal[$iday]['celebration'] = $epoctave[$dow];
            } else {
                $this->cal[$iday]['celebration'] = $bl;
                $this->cal[$iday]['rank'] = self::LORD;
            }
        }
        return $ibl;
    }

    // lent.c: returns the day of Ash Wednesday
    private function lent(): int
    {
        static $ashWeek = [
            'Ash Wednesday', 'Thursday after Ash Wednesday', 'Friday after Ash Wednesday',
            'Saturday after Ash Wednesday',
        ];
        static $awRank = [self::ASHWED, self::WEEKDAY, self::WEEKDAY, self::WEEKDAY];
        static $holyWeek = [
            'Palm Sunday', 'Monday of Holy Week', 'Tuesday of Holy Week', 'Wednesday of Holy Week',
            'Holy Thursday', 'Good Friday', 'Easter Vigil',
        ];
        static $hwColor = [
            self::RED, self::PURPLE, self::PURPLE, self::PURPLE, self::WHITE, self::RED, self::WHITE,
        ];
        static $hwRank = [
            self::SUNDAY, self::HOLYWEEK, self::HOLYWEEK, self::HOLYWEEK,
            self::TRIDUUM, self::TRIDUUM, self::TRIDUUM,
        ];

        $iaw = $this->edoy - 46;
        for ($iday = 0; $iday < 4; $iday++) {
            $this->set($iday + $iaw, $ashWeek[$iday], self::LENT, self::PURPLE, $awRank[$iday]);
        }
        $this->cal[$iaw]['season'] = self::PASCHAL;

        $lent1 = $iaw + 4;
        $lent4 = $iaw + 25;
        $palm = $this->edoy - 7;
        $dow = self::SUNDAY_DOW;
        $week = 1;
        for ($iday = $lent1; $iday < $palm; $iday++) {
            $this->set($iday, self::gencel(self::LENT, $week, $dow), self::LENT,
                $iday === $lent4 ? self::ROSE : self::PURPLE);
            $dow = ($dow + 1) % 7;
            if ($dow === 0) {
                $week++;
            }
        }
        $dow = self::SUNDAY_DOW;
        for ($iday = $palm; $iday < $this->edoy; $iday++) {
            $this->set($iday, $holyWeek[$dow], self::PASCHAL, $hwColor[$dow], $hwRank[$dow]);
            $dow++;
        }
        return $iaw;
    }

    // easter.c: returns the day of Pentecost Sunday
    private function easter(): int
    {
        static $octave = [
            'Easter Sunday', 'Monday in the Octave of Easter', 'Tuesday in the Octave of Easter',
            'Wednesday in the Octave of Easter', 'Thursday in the Octave of Easter',
            'Friday in the Octave of Easter', 'Saturday in the Octave of Easter', 'Second Sunday of Easter',
        ];
        $east = $this->edoy;
        foreach ($octave as $iday => $name) {
            $this->set($iday + $east, $name, self::PASCHAL, self::WHITE, self::SOLEMNITY);
        }
        $ips = $east + 49;
        $this->set($ips, 'Pentecost Sunday', self::EASTER, self::RED, self::SOLEMNITY);

        $dow = 1;
        $week = 2;
        for ($iday = $east + 8; $iday < $ips; $iday++) {
            $this->set($iday, self::gencel(self::EASTER, $week, $dow), self::EASTER, self::WHITE, $this->cal[$iday]['rank']);
            $dow = ($dow + 1) % 7;
            if ($dow === 0) {
                $week++;
            }
        }

        $iat = $this->asOnSunday ? $east + 42 : $east + 39;
        $this->set($iat, 'Ascension of the Lord', self::EASTER, self::WHITE, self::SOLEMNITY);
        $this->set($east + 56, 'Trinity Sunday', self::ORDINARY, self::WHITE, self::SOLEMNITY);
        $icc = $this->ccOnThursday ? $east + 60 : $east + 63;
        $this->set($icc, 'Corpus Christi', self::ORDINARY, self::WHITE, self::SOLEMNITY);
        $this->set($east + 68, 'Sacred Heart of Jesus', self::ORDINARY, self::WHITE, self::SOLEMNITY);
        $this->set($east + 69, 'Immaculate Heart of Mary', self::ORDINARY, self::WHITE, self::MEMORIAL);
        return $ips;
    }

    // advent.c: returns the day of the First Sunday of Advent
    private function advent(): int
    {
        static $adventLen = [28, 22, 23, 24, 25, 26, 27];
        $advent1 = $this->cdoy - $adventLen[$this->dow($this->cdoy)];
        $advent3 = $advent1 + 14;
        $dow = self::SUNDAY_DOW;
        $week = 1;
        for ($iday = $advent1; $iday < $this->cdoy; $iday++) {
            $this->set($iday, self::gencel(self::ADVENT, $week, $dow), self::ADVENT,
                $iday === $advent3 ? self::ROSE : self::PURPLE, $this->cal[$iday]['rank']);
            $dow = ($dow + 1) % 7;
            if ($dow === 0) {
                $week++;
            }
        }
        return $advent1;
    }

    // xmas2.c
    private function christmas2(): void
    {
        static $octave = [
            'Second day in the Octave of Christmas', 'Third day in the Octave of Christmas',
            'Fourth day in the Octave of Christmas', 'Fifth day in the Octave of Christmas',
            'Sixth day in the Octave of Christmas', 'Seventh day in the Octave of Christmas',
        ];
        $holyFamily = 'Holy Family';
        $dec26 = $this->cdoy + 1;
        for ($iday = $dec26; $iday < $this->numdays; $iday++) {
            if ($this->dow($iday) === self::SUNDAY_DOW) {
                $this->set($iday, $holyFamily, self::CHRISTMAS, self::WHITE, self::LORD);
            } else {
                $this->set($iday, $octave[$iday - $dec26], self::CHRISTMAS, self::WHITE, $this->cal[$iday]['rank']);
            }
        }
        if ($this->dow($this->cdoy) === self::SUNDAY_DOW) {
            // Christmas on a Sunday: Holy Family is on Friday Dec 30
            $this->set(self::doy($this->year, 12, 30), $holyFamily, self::CHRISTMAS, self::WHITE, self::LORD);
        }
    }

    // proper.c: lay the fixed-date celebrations over the seasons
    private function proper(): void
    {
        foreach ($this->fixed as $index => $fix) {
            $iday = self::doy($this->year, $fix['month'], $fix['day']);
            // Solemnities falling in Holy Week or the Easter octave are transferred
            while ($this->cal[$iday]['season'] === self::PASCHAL && $fix['rank'] === self::SOLEMNITY) {
                if ($this->cal[$iday - 1]['season'] !== self::PASCHAL || $fix['day'] === 19) {
                    $iday--;
                } else {
                    $iday++;
                }
            }

            if ($fix['rank'] === self::OPTIONAL && !$this->printOptionals) {
                $overwrite = false;
            } elseif ($this->cal[$iday]['season'] === self::LENT && $fix['rank'] === self::MEMORIAL && !$this->printOptionals) {
                $overwrite = false;
            } elseif ($fix['rank'] > $this->cal[$iday]['rank']) {
                $overwrite = true;
                // Sundays of Lent, Advent and Easter give way: the celebration moves to Monday
                if ($this->cal[$iday]['rank'] === self::SUNDAY
                    && in_array($this->cal[$iday]['season'], [self::LENT, self::ADVENT, self::EASTER], true)) {
                    $iday++;
                }
            } else {
                $overwrite = false;
            }

            if ($overwrite) {
                $this->cal[$iday]['celebration'] = $fix['name'];
                $this->cal[$iday]['rank'] = $fix['rank'];
                $this->cal[$iday]['fixed'] = $index;
                if ($this->cal[$iday]['rank'] < self::FEAST && $this->cal[$iday]['season'] === self::LENT) {
                    $this->cal[$iday]['rank'] = self::COMMEMORATION;
                } elseif ($fix['color'] !== self::NOCOLOR) {
                    $this->cal[$iday]['color'] = $fix['color'];
                }
            }
        }
    }

    // ord1.c: Ordinary Time after the Baptism of the Lord, counting forward
    private function ordinary1(int $ibl, int $iaw): void
    {
        $week = 1;
        $dow = $this->dow($ibl) + 1;
        for ($iday = $ibl + 1; $iday < $iaw; $iday++) {
            $this->ordinaryDay($iday, $week, $dow);
            $dow = ($dow + 1) % 7;
            if ($dow === 0) {
                $week++;
            }
        }
    }

    // ord2.c: Ordinary Time after Pentecost, counting back from Christ the King
    private function ordinary2(int $ips, int $iav): void
    {
        $ick = $iav - 7;
        $this->set($ick, 'Christ the King', self::ORDINARY, self::WHITE, self::SOLEMNITY);
        $week = 34;
        $dow = 6;
        for ($iday = $iav - 1; $iday > $ips; $iday--) {
            $this->ordinaryDay($iday, $week, $dow);
            $dow--;
            if ($dow === -1) {
                $week--;
                $dow = 6;
            }
        }
    }

    private function ordinaryDay(int $iday, int $week, int $dow): void
    {
        if ($this->cal[$iday]['rank'] === self::WEEKDAY
            || ($dow === 0 && $this->cal[$iday]['rank'] < self::LORD)) {
            $this->set($iday, self::gencel(self::ORDINARY, $week, $dow), self::ORDINARY, self::GREEN);
        }
    }

    private function rows(): array
    {
        $jan1 = gmmktime(0, 0, 0, 1, 1, $this->year);
        $rows = [];
        foreach ($this->cal as $iday => $day) {
            $fix = $day['fixed'] !== null ? $this->fixed[$day['fixed']] : null;
            $dow = $this->dow($iday);

            if ($day['season'] === self::PASCHAL) {
                $season = $iday < $this->edoy ? 'Lent' : 'Easter';
            } elseif ($iday <= $this->ibl || $iday >= $this->cdoy) {
                // The C code never marks Jan 1 and Dec 25 as Christmas (it does not need to)
                $season = 'Christmas';
            } else {
                $season = ['Ordinary Time', 'Advent', 'Christmas', 'Lent', 'Easter'][$day['season']];
            }

            $rows[] = [
                'date' => gmdate('Y-m-d', $jan1 + $iday * 86400),
                'celebration' => $fix['name'] ?? $day['celebration'] ?? '',
                'class' => $fix['class'] ?? ($dow === self::SUNDAY_DOW ? 'C' : null),
                'rank' => self::RANK_NAMES[$day['rank']],
                'color' => self::COLOR_NAMES[$day['color']],
                'season' => $season,
                'notes' => $fix['notes'] ?? null,
                'votive' => $day['color'] === self::GREEN ? (self::VOTIVE_MASSES[$dow] ?? null) : null,
                'devotion' => self::DEVOTIONS[$dow] ?? null,
            ];
        }
        return $rows;
    }
}
