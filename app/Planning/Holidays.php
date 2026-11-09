<?php

namespace App\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The days a post can hang on: Greek holidays, name days and Cretan anniversaries, Cyprus's own when the client is there,
 * the holidays of the guests' countries, awareness and celebration days and months (marked on their first day).
 * Orthodox and Western Easter move; the rest is fixed or "the nth weekday of a month".
 */
final class Holidays
{
    /** @return array<string, string> date => label(s), inside the range, sorted; several on one day are joined with " · " */
    public static function between(CarbonInterface $from, CarbonInterface $to, array $countries = []): array
    {
        $days = [];
        for ($year = $from->year; $year <= $to->year; $year++) {
            $days += self::forYear($year, $countries);
        }
        $days = array_filter($days, fn (string $date) => $date >= $from->toDateString() && $date <= $to->toDateString(), ARRAY_FILTER_USE_KEY);
        ksort($days);

        return $days;
    }

    /** @return list<string> "date label" lines for a prompt */
    public static function lines(CarbonInterface $from, CarbonInterface $to, array $countries = []): array
    {
        $days = self::between($from, $to, $countries);

        return array_map(fn (string $date, string $label) => "{$date} {$label}", array_keys($days), $days);
    }

    public static function inCyprus(?string $location): bool
    {
        return (bool) preg_match('/cyprus|κ[υύ]προς/iu', (string) $location);
    }

    /** The countries whose holidays a client's guests care about: from the caption languages, plus Cyprus from the location. */
    public const COUNTRIES = ['en' => ['UK', 'IE', 'US'], 'de' => ['DE', 'AT', 'CH'], 'fr' => ['FR', 'BE', 'CH'], 'it' => ['IT'], 'es' => ['ES'], 'nl' => ['NL', 'BE'], 'pl' => ['PL'], 'ru' => ['RU'], 'sv' => ['SE'], 'da' => ['DK'], 'no' => ['NO'], 'nb' => ['NO'], 'fi' => ['FI'], 'pt' => ['PT']];

    /** @return list<string> */
    public static function countries(array $profile): array
    {
        $languages = [...($profile['languages'] ?? []), ...($profile['extra_languages'] ?? [])];
        $codes = array_merge([], ...array_map(fn ($lang) => self::COUNTRIES[strtolower(substr((string) $lang, 0, 2))] ?? [], $languages));
        if (self::inCyprus($profile['location'] ?? null)) {
            $codes[] = 'CY';
        }

        return array_values(array_unique($codes));
    }

    /**
     * Greek days and world days always; an entry tagged with countries only when one of them is in $countries.
     *
     * @return array<string, string>
     */
    public static function forYear(int $year, array $countries = []): array
    {
        $days = [];
        foreach ([self::greek($year), self::nameDays($year), self::cyprus($year), self::europe($year), self::world($year)] as $section) {
            foreach ($section as $entry) {
                [$date, $label] = $entry;
                if (isset($entry[2]) && ! array_intersect(explode(' ', $entry[2]), $countries)) {
                    continue;
                }
                $days[$date] = isset($days[$date]) ? "{$days[$date]} · {$label}" : $label;
            }
        }
        ksort($days);

        return $days;
    }

    /** Meeus's Julian algorithm, moved to the Gregorian calendar (13 days, right until 2099). */
    public static function orthodoxEaster(int $year): CarbonImmutable
    {
        $d = (19 * ($year % 19) + 15) % 30;
        $e = (2 * ($year % 4) + 4 * ($year % 7) - $d + 34) % 7;
        $n = $d + $e + 114;

        return CarbonImmutable::create($year, intdiv($n, 31), $n % 31 + 1)->addDays(13);
    }

    /** The anonymous Gregorian algorithm: Easter of the Catholic and Protestant countries. */
    public static function westernEaster(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);

        return CarbonImmutable::create($year, intdiv($h + $l - 7 * $m + 114, 31), ($h + $l - 7 * $m + 114) % 31 + 1);
    }

    /** @return list<array{string, string}> */
    private static function greek(int $year): array
    {
        $easter = self::orthodoxEaster($year);
        $georgios = CarbonImmutable::create($year, 4, 23);

        return [
            ["{$year}-01-01", 'Πρωτοχρονιά (Αγίου Βασιλείου)'],
            ["{$year}-01-06", 'Θεοφάνεια'],
            ["{$year}-01-30", 'Τρεις Ιεράρχες (σχολεία)'],
            ["{$year}-02-09", 'Παγκόσμια Ημέρα Ελληνικής Γλώσσας'],
            ["{$year}-02-14", "Αγίου Βαλεντίνου (Valentine's Day)"],
            [$easter->subDays(59)->toDateString(), 'Τσικνοπέμπτη'],
            [$easter->subDays(57)->toDateString(), 'Ψυχοσάββατο'],
            [$easter->subDays(56)->toDateString(), 'Κυριακή της Απόκρεω'],
            [$easter->subDays(49)->toDateString(), 'Κυριακή της Τυρινής (καρναβάλι Πάτρας, Ρεθύμνου)'],
            [$easter->subDays(48)->toDateString(), 'Καθαρά Δευτέρα (Clean Monday)'],
            [$easter->subDays(43)->toDateString(), 'Αγίων Θεοδώρων (Θόδωρος, Θεοδώρα)'],
            ["{$year}-03-08", "Παγκόσμια Ημέρα της Γυναίκας (International Women's Day: holiday in RU, mimosa in IT)"],
            ["{$year}-03-25", '25η Μαρτίου (Ευαγγελισμός: Βαγγέλης, Ευαγγελία)'],
            [$easter->subDays(8)->toDateString(), 'Σάββατο του Λαζάρου'],
            [$easter->subDays(7)->toDateString(), 'Κυριακή των Βαΐων (Βάγια)'],
            [$easter->subDays(3)->toDateString(), 'Μεγάλη Πέμπτη'],
            [$easter->subDays(2)->toDateString(), 'Μεγάλη Παρασκευή (Good Friday)'],
            [$easter->subDay()->toDateString(), 'Μεγάλο Σάββατο (Ανάσταση)'],
            [$easter->toDateString(), 'Πάσχα (Orthodox Easter)'],
            [$easter->addDay()->toDateString(), 'Δευτέρα του Πάσχα'],
            [$easter->addDays(5)->toDateString(), 'Ζωοδόχου Πηγής'],
            // Saint George moves to Easter Monday when the 23rd falls in Lent or on Easter
            [($georgios->lte($easter) ? $easter->addDay() : $georgios)->toDateString(), 'Αγίου Γεωργίου (Γιώργος, Γεωργία)'],
            ["{$year}-05-01", 'Πρωτομαγιά'],
            ["{$year}-05-01", 'Labour Day (most of Europe)', 'DE FR IT ES NL AT CH PL PT BE SE NO DK FI RU'],
            [CarbonImmutable::parse("second sunday of may {$year}")->toDateString(), 'Γιορτή της Μητέρας'],
            [CarbonImmutable::parse("second sunday of may {$year}")->toDateString(), "Mother's Day (DE, IT, AT, CH, NL, BE, US)", 'DE IT AT CH NL BE US'],
            ["{$year}-05-19", 'Ημέρα Μνήμης Γενοκτονίας των Ποντίων'],
            ["{$year}-05-20", 'Επέτειος Μάχης της Κρήτης (20–30 Μαΐου)'],
            ["{$year}-05-21", 'Αγίων Κωνσταντίνου και Ελένης (Κώστας, Ελένη)'],
            [$easter->addDays(39)->toDateString(), 'Ανάληψη'],
            [$easter->addDays(49)->toDateString(), 'Πεντηκοστή'],
            [$easter->addDays(50)->toDateString(), 'Αγίου Πνεύματος (Whit Monday, Κατακλυσμός στην Κύπρο)'],
            [CarbonImmutable::parse("third sunday of june {$year}")->toDateString(), 'Γιορτή του Πατέρα'],
            [CarbonImmutable::parse("third sunday of june {$year}")->toDateString(), "Father's Day (UK, IE, US, FR, NL)", 'UK IE US FR NL'],
            ["{$year}-08-06", 'Μεταμόρφωση του Σωτήρος'],
            ["{$year}-08-15", 'Δεκαπενταύγουστος (Μαρία, Παναγιώτης, Δέσποινα)'],
            ["{$year}-08-15", 'Assumption, Ferragosto (IT, FR, ES, AT, PL, BE, PT)', 'IT FR ES AT PL BE PT'],
            ["{$year}-08-25", 'Αγίου Τίτου (πολιούχος Ηρακλείου)'],
            ["{$year}-10-28", '28η Οκτωβρίου (Επέτειος του Όχι, Ohi Day)'],
            ["{$year}-11-08", 'Ολοκαύτωμα Αρκαδίου (Ρέθυμνο) · Ταξιαρχών (Μιχάλης, Γαβριήλ, Αγγελική)'],
            ["{$year}-11-11", 'Αγίου Μηνά (πολιούχος Ηρακλείου)'],
            ["{$year}-11-11", 'Armistice Day (FR, BE)', 'FR BE'],
            ["{$year}-11-11", 'St Martin\'s Day (DE, NL)', 'DE NL'],
            ["{$year}-11-17", 'Επέτειος Πολυτεχνείου'],
            ["{$year}-11-21", 'Εισόδια της Θεοτόκου · Ημέρα Ενόπλων Δυνάμεων'],
            ["{$year}-12-24", 'Παραμονή Χριστουγέννων (κάλαντα)'],
            ["{$year}-12-25", 'Χριστούγεννα · Christmas Day'],
            ["{$year}-12-26", 'Δεύτερη μέρα Χριστουγέννων'],
            ["{$year}-12-26", 'Boxing Day (UK), St Stephen\'s Day', 'UK IE DE AT CH IT NL PL'],
            ["{$year}-12-31", 'Παραμονή Πρωτοχρονιάς · New Year\'s Eve'],
        ];
    }

    /** The name days half the country wishes on; the big ones that move are in greek(). */
    private static function nameDays(int $year): array
    {
        return [
            ["{$year}-01-07", 'Αγίου Ιωάννη (Γιάννης, Ιωάννα)'],
            ["{$year}-01-07", 'Orthodox Christmas (RU, RS)', 'RU'],
            ["{$year}-01-17", 'Αγίου Αντωνίου (Αντώνης, Αντωνία)'],
            ["{$year}-01-18", 'Αγίου Αθανασίου (Θανάσης, Αθανασία)'],
            ["{$year}-02-10", 'Αγίου Χαραλάμπους (Χαράλαμπος, Χαρά)'],
            ["{$year}-05-05", 'Αγίας Ειρήνης (Ειρήνη)'],
            ["{$year}-05-05", 'Liberation Day (NL)', 'NL'],
            ["{$year}-05-05", 'World Hand Hygiene Day'],
            ["{$year}-06-29", 'Αγίων Πέτρου και Παύλου (Πέτρος, Παύλος)'],
            ["{$year}-07-17", 'Αγίας Μαρίνας (Μαρίνα) · World Emoji Day'],
            ["{$year}-07-20", 'Προφήτη Ηλία (Ηλίας)'],
            ["{$year}-07-26", 'Αγίας Παρασκευής (Παρασκευή)'],
            ["{$year}-07-27", 'Αγίου Παντελεήμονα (Παντελής, προστάτης των γιατρών και φαρμακοποιών)'],
            ["{$year}-08-30", 'Αγίου Αλεξάνδρου (Αλέξανδρος, Αλεξάνδρα)'],
            ["{$year}-09-14", 'Ύψωση Τιμίου Σταυρού (Σταύρος, Σταυρούλα)'],
            ["{$year}-09-17", 'Αγίας Σοφίας (Σοφία, Ελπίδα, Πίστη, Αγάπη)'],
            ["{$year}-10-26", 'Αγίου Δημητρίου (Δημήτρης, Δήμητρα)'],
            ["{$year}-10-26", 'National Day (AT)', 'AT'],
            ["{$year}-11-01", 'Αγίων Αναργύρων (Κοσμάς, Δαμιανός)'],
            ["{$year}-11-01", 'All Saints\' Day (FR, IT, ES, PL, AT, BE)', 'FR IT ES PL AT BE'],
            ["{$year}-11-01", 'World Vegan Day'],
            ["{$year}-11-14", 'Αγίου Φιλίππου (Φίλιππος) · Παγκόσμια Ημέρα Διαβήτη (World Diabetes Day)'],
            ["{$year}-11-25", 'Αγίας Αικατερίνης (Κατερίνα) · International Day for the Elimination of Violence against Women'],
            ["{$year}-11-30", 'Αγίου Ανδρέα (Ανδρέας)'],
            ["{$year}-12-04", 'Αγίας Βαρβάρας (Βαρβάρα)'],
            ["{$year}-12-06", 'Αγίου Νικολάου (Νίκος, Νικολέτα)'],
            ["{$year}-12-06", 'St Nicholas (DE, AT, NL, BE, PL)', 'DE AT NL BE PL'],
            ["{$year}-12-12", 'Αγίου Σπυρίδωνα (Σπύρος)'],
            ["{$year}-12-15", 'Αγίου Ελευθερίου (Λευτέρης)'],
            ["{$year}-12-27", 'Αγίου Στεφάνου (Στέφανος, Στεφανία)'],
        ];
    }

    private static function cyprus(int $year): array
    {
        return [["{$year}-04-01", 'Εθνική επέτειος Κύπρου', 'CY'], ["{$year}-10-01", 'Ανεξαρτησία Κύπρου', 'CY']];
    }

    /** The holidays of the countries the guests come from, labelled by country. */
    private static function europe(int $year): array
    {
        $easter = self::westernEaster($year);
        $pentecost = $easter->addDays(49);
        $frenchMothers = CarbonImmutable::parse("last sunday of may {$year}");
        if ($frenchMothers->eq($pentecost)) {
            $frenchMothers = CarbonImmutable::parse("first sunday of june {$year}");
        }

        return [
            ["{$year}-01-06", 'Epiphany (IT, ES, AT, PL, south DE)', 'DE IT ES AT PL'],
            [$easter->subDays(48)->toDateString(), 'Carnival Monday, Rosenmontag (DE, NL, BE)', 'DE NL BE'],
            [$easter->subDays(47)->toDateString(), 'Shrove Tuesday, Mardi Gras, Pancake Day (UK, FR, IT)', 'UK IE FR IT'],
            [$easter->subDays(46)->toDateString(), 'Ash Wednesday (Western Lent begins)', 'DE UK IE US FR IT ES NL AT CH PL PT BE SE NO DK FI'],
            ["{$year}-02-23", 'Defender of the Fatherland Day (RU)', 'RU'],
            ["{$year}-03-17", 'St Patrick\'s Day (IE, UK)', 'UK IE'],
            ["{$year}-03-19", 'St Joseph\'s Day: Father\'s Day (IT, ES)', 'IT ES'],
            [$easter->subDays(21)->toDateString(), 'Mothering Sunday (UK)', 'UK IE'],
            [$easter->subDays(2)->toDateString(), 'Good Friday (Western: DE, UK, ES, Nordics)', 'DE UK IE US FR IT ES NL AT CH PL PT BE SE NO DK FI'],
            [$easter->toDateString(), 'Easter Sunday (Western)', 'DE UK IE US FR IT ES NL AT CH PL PT BE SE NO DK FI'],
            [$easter->addDay()->toDateString(), 'Easter Monday (Western: DE, UK, FR, IT, NL, AT, CH, PL)', 'DE UK IE US FR IT ES NL AT CH PL PT BE SE NO DK FI'],
            ["{$year}-04-25", 'Liberation Day (IT)', 'IT'],
            ["{$year}-04-27", 'King\'s Day (NL)', 'NL'],
            [CarbonImmutable::parse("first sunday of may {$year}")->toDateString(), 'Mother\'s Day (ES, PT)', 'ES PT'],
            [CarbonImmutable::parse("first monday of may {$year}")->toDateString(), 'Early May bank holiday (UK)', 'UK IE'],
            ["{$year}-05-08", 'Victory in Europe Day (FR)', 'FR'],
            ["{$year}-05-08", 'World Red Cross Day'],
            ["{$year}-05-26", 'Mother\'s Day (PL)', 'PL'],
            ["{$year}-05-09", 'Europe Day'],
            ["{$year}-05-09", 'Victory Day (RU)', 'RU'],
            [$easter->addDays(39)->toDateString(), 'Ascension Day (DE, FR, NL, BE, AT, CH, Nordics)', 'DE FR NL AT CH BE SE NO DK FI'],
            [$easter->addDays(39)->toDateString(), 'Vatertag (DE)', 'DE'],
            [$frenchMothers->toDateString(), 'Fête des Mères (FR)', 'FR'],
            [CarbonImmutable::parse("last monday of may {$year}")->toDateString(), 'Spring bank holiday (UK)', 'UK IE'],
            [$pentecost->toDateString(), 'Pentecost (Western)', 'DE UK IE US FR IT ES NL AT CH PL PT BE SE NO DK FI'],
            [$pentecost->addDay()->toDateString(), 'Whit Monday (DE, FR, NL, BE, AT, CH)', 'DE FR NL AT CH BE'],
            [$easter->addDays(60)->toDateString(), 'Corpus Christi (south DE, AT, PL, PT)', 'DE AT PL PT'],
            ["{$year}-06-02", 'Republic Day (IT)', 'IT'],
            ["{$year}-06-06", 'National Day (SE)', 'SE'],
            ["{$year}-06-12", 'Russia Day (RU)', 'RU'],
            [CarbonImmutable::create($year, 6, 18)->next(CarbonInterface::FRIDAY)->toDateString(), 'Midsummer Eve (SE, FI)', 'SE FI'],
            ["{$year}-06-23", 'Sankt Hans, Midsummer Eve (NO, DK)', 'NO DK'],
            ["{$year}-06-23", 'Father\'s Day (PL)', 'PL'],
            ["{$year}-07-14", 'Bastille Day (FR)', 'FR'],
            ["{$year}-07-21", 'National Day (BE)', 'BE'],
            ["{$year}-08-01", 'National Day (CH)', 'CH'],
            [CarbonImmutable::parse("last monday of august {$year}")->toDateString(), 'Summer bank holiday (UK)', 'UK IE'],
            [CarbonImmutable::create($year, 9, 15)->next(CarbonInterface::SATURDAY)->toDateString(), 'Oktoberfest opens (Munich)', 'DE AT CH'],
            ["{$year}-10-03", 'German Unity Day (DE)', 'DE'],
            ["{$year}-10-12", 'National Day (ES)', 'ES'],
            ["{$year}-10-31", 'Halloween'],
            ["{$year}-10-31", 'Reformation Day (north DE)', 'DE'],
            ["{$year}-11-04", 'Unity Day (RU)', 'RU'],
            ["{$year}-11-05", 'Bonfire Night (UK)', 'UK IE'],
            [CarbonImmutable::parse("second sunday of november {$year}")->toDateString(), 'Father\'s Day (SE, FI, NO)', 'SE NO FI'],
            ["{$year}-12-05", 'Sinterklaas (NL)', 'NL'],
            ["{$year}-12-05", 'International Volunteer Day'],
            ["{$year}-12-08", 'Immaculate Conception (IT, ES, AT)', 'IT ES AT'],
            ["{$year}-12-13", 'St Lucia (SE)', 'SE'],
        ];
    }

    /** Awareness and celebration days, and the months (on their first day), that hospitality, food and pharmacy clients post about. */
    private static function world(int $year): array
    {
        $thanksgiving = CarbonImmutable::parse("fourth thursday of november {$year}");

        return [
            ["{$year}-01-01", 'Veganuary · Dry January (all month)'],
            ["{$year}-01-21", 'International Hug Day'],
            ["{$year}-01-24", 'International Day of Education'],
            ["{$year}-02-04", 'Παγκόσμια Ημέρα κατά του Καρκίνου (World Cancer Day)'],
            ["{$year}-02-09", 'International Pizza Day'],
            ["{$year}-02-11", 'International Day of Women and Girls in Science'],
            ["{$year}-02-17", 'Random Acts of Kindness Day'],
            [CarbonImmutable::create($year, 2, 1)->endOfMonth()->toDateString(), 'Rare Disease Day'],
            ["{$year}-03-03", 'World Wildlife Day'],
            ["{$year}-03-04", 'World Obesity Day'],
            [CarbonImmutable::parse("second thursday of march {$year}")->toDateString(), 'World Kidney Day'],
            [CarbonImmutable::create($year, 3, 20)->previous(CarbonInterface::FRIDAY)->toDateString(), 'World Sleep Day'],
            ["{$year}-03-20", 'Παγκόσμια Ημέρα Ευτυχίας (International Day of Happiness) · World Oral Health Day · spring equinox'],
            ["{$year}-03-21", 'World Poetry Day · International Day of Forests · World Down Syndrome Day'],
            ["{$year}-03-22", 'Παγκόσμια Ημέρα Νερού (World Water Day)'],
            ["{$year}-03-27", 'World Theatre Day'],
            [CarbonImmutable::parse("last saturday of march {$year}")->toDateString(), 'Earth Hour'],
            [CarbonImmutable::parse("last sunday of march {$year}")->toDateString(), 'Clocks go forward (EU summer time)'],
            ["{$year}-04-01", 'Stress Awareness Month (all April)'],
            ["{$year}-04-02", 'World Autism Awareness Day'],
            ["{$year}-04-07", 'Παγκόσμια Ημέρα Υγείας (World Health Day)'],
            ["{$year}-04-11", 'World Parkinson\'s Day'],
            ["{$year}-04-22", 'Ημέρα της Γης (Earth Day)'],
            ["{$year}-04-23", 'Παγκόσμια Ημέρα Βιβλίου (World Book Day)'],
            ["{$year}-04-29", 'International Dance Day'],
            ["{$year}-05-01", 'Skin Cancer Awareness Month, sun safety (all May)'],
            ["{$year}-05-12", 'International Nurses Day'],
            ["{$year}-05-13", 'World Cocktail Day'],
            ["{$year}-05-15", 'International Day of Families'],
            ["{$year}-05-17", 'World Hypertension Day'],
            ["{$year}-05-18", 'Διεθνής Ημέρα Μουσείων (International Museum Day)'],
            ["{$year}-05-20", 'World Bee Day'],
            ["{$year}-05-21", 'International Tea Day'],
            ["{$year}-05-31", 'World No Tobacco Day'],
            ["{$year}-06-01", 'Παγκόσμια Ημέρα Παιδιού (International Children\'s Day) · Pride Month (all June) · Global Day of Parents'],
            ["{$year}-06-03", 'World Bicycle Day'],
            ["{$year}-06-05", 'Παγκόσμια Ημέρα Περιβάλλοντος (World Environment Day)'],
            ["{$year}-06-08", 'Παγκόσμια Ημέρα Ωκεανών (World Oceans Day)'],
            [CarbonImmutable::parse("second saturday of june {$year}")->toDateString(), 'Global Wellness Day'],
            ["{$year}-06-14", 'World Blood Donor Day'],
            ["{$year}-06-18", 'Sustainable Gastronomy Day · International Picnic Day'],
            ["{$year}-06-21", 'Παγκόσμια Ημέρα Μουσικής (World Music Day, Fête de la Musique) · International Yoga Day · summer solstice'],
            ["{$year}-06-30", 'Social Media Day'],
            ["{$year}-07-01", 'Plastic Free July (all month)'],
            ["{$year}-07-07", 'World Chocolate Day'],
            ["{$year}-07-28", 'World Hepatitis Day'],
            ["{$year}-07-30", 'International Day of Friendship'],
            ["{$year}-08-01", 'World Breastfeeding Week (1–7 August)'],
            [CarbonImmutable::parse("first friday of august {$year}")->toDateString(), 'International Beer Day'],
            ["{$year}-08-08", 'International Cat Day'],
            ["{$year}-08-12", 'International Youth Day'],
            ["{$year}-08-19", 'Παγκόσμια Ημέρα Φωτογραφίας (World Photography Day)'],
            [CarbonImmutable::parse("third saturday of august {$year}")->toDateString(), 'World Honey Bee Day'],
            ["{$year}-08-26", 'International Dog Day'],
            ["{$year}-09-01", 'World Alzheimer\'s Month (all September)'],
            [CarbonImmutable::parse("third saturday of september {$year}")->toDateString(), 'World Cleanup Day'],
            ["{$year}-09-21", 'World Alzheimer\'s Day · International Day of Peace'],
            ["{$year}-09-22", 'World Car-Free Day · autumn equinox'],
            ["{$year}-09-25", 'Παγκόσμια Ημέρα Φαρμακοποιού (World Pharmacists Day)'],
            ["{$year}-09-26", 'European Day of Languages'],
            ["{$year}-09-27", 'Παγκόσμια Ημέρα Τουρισμού (World Tourism Day)'],
            ["{$year}-09-29", 'Παγκόσμια Ημέρα Καρδιάς (World Heart Day)'],
            ["{$year}-10-01", 'Οκτώβριος: μήνας ευαισθητοποίησης για τον καρκίνο του μαστού (Pink October, all month) · Παγκόσμια Ημέρα Καφέ (International Coffee Day) · World Vegetarian Day · International Day of Older Persons'],
            [CarbonImmutable::parse("first friday of october {$year}")->toDateString(), 'World Smile Day'],
            ["{$year}-10-04", 'Παγκόσμια Ημέρα Ζώων (World Animal Day)'],
            ["{$year}-10-05", 'World Teachers\' Day'],
            ["{$year}-10-10", 'Παγκόσμια Ημέρα Ψυχικής Υγείας (World Mental Health Day)'],
            ["{$year}-10-12", 'World Arthritis Day'],
            [CarbonImmutable::parse("second thursday of october {$year}")->toDateString(), 'World Sight Day'],
            ["{$year}-10-15", 'Global Handwashing Day'],
            ["{$year}-10-16", 'Παγκόσμια Ημέρα Διατροφής (World Food Day) · World Bread Day'],
            ["{$year}-10-20", 'World Osteoporosis Day'],
            ["{$year}-10-25", 'World Pasta Day'],
            [CarbonImmutable::parse("last sunday of october {$year}")->toDateString(), 'Clocks go back (EU winter time)'],
            ["{$year}-10-29", 'World Stroke Day'],
            ["{$year}-11-01", 'Movember: men\'s health (all month)'],
            ["{$year}-11-11", 'Singles\' Day'],
            ["{$year}-11-13", 'World Kindness Day'],
            ["{$year}-11-18", 'World Antimicrobial Awareness Week (18–24 November)'],
            ["{$year}-11-19", 'International Men\'s Day'],
            ["{$year}-11-20", 'Παγκόσμια Ημέρα Δικαιωμάτων του Παιδιού (World Children\'s Day)'],
            [$thanksgiving->toDateString(), 'Thanksgiving (US)', 'US'],
            [$thanksgiving->addDay()->toDateString(), 'Black Friday'],
            [$thanksgiving->addDays(4)->toDateString(), 'Cyber Monday'],
            [$thanksgiving->addDays(5)->toDateString(), 'Giving Tuesday'],
            ["{$year}-12-01", 'Παγκόσμια Ημέρα κατά του AIDS'],
            ["{$year}-12-03", 'Παγκόσμια Ημέρα Ατόμων με Αναπηρία'],
            ["{$year}-12-10", 'Human Rights Day'],
            ["{$year}-12-11", 'International Mountain Day'],
            ["{$year}-12-21", 'Winter solstice'],
        ];
    }
}
