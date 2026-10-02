<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Vendor_Map {
    public static function assign(string $name): string {
        $key = self::normalize($name);
        if ($key === '') return '';
        $aliases = [
            'inventory soda bib diet pepsi' => 'diet pepsi', 'inventory soda bib dr pepper' => 'dr pepper',
            'inventory soda bib lemonade' => 'lemonade', 'inventory soda bib mountain dew' => 'mountain dew',
            'inventory soda bib orange crush' => 'orange crush', 'inventory soda bib pepsi' => 'pepsi',
            'inventory soda bib root beer' => 'mug rb', 'inventory soda bib sobe lifewater' => 'sobe water',
            'inventory soda bib starry lemon lime' => 'starry', 'inventory soda bib unsweetened iced tea' => 'ice tea',
            'inventory soda co2' => 'co2', 'inventory popping oil' => 'star popcorn oil',
            'inventory cups beer 12oz' => '12oz beer cups', 'inventory cups wine' => 'wine cups',
            'inventory cups bottomless' => 'btm cups 44', 'inventory cups large' => 'lg cups 32',
            'inventory cups medium' => 'med cups 24', 'inventory cups small' => 'sm cups 16',
            'inventory lids 44 oz' => 'btm lids 44', 'inventory lids 32 oz' => 'large lids 32',
            'inventory bags bottomless 170oz' => 'btm eco bags 170oz', 'inventory bags large 130oz' => 'lg eco bags 130oz',
            'inventory bags medium 85oz' => 'med eco bags 85oz', 'inventory bags small 46oz' => 'sm eco bags 46oz',
            'inventory flavacol salt' => 'flavacol', 'inventory paper towels' => 'paper towels',
            'inventory paper boats' => 'paper food tray', 'inventory straws' => 'straws',
            'inventory glass cleaner' => 'glass cleaner', 'inventory soap gojo hand 67 oz' => 'gojo soap 7220',
            'hot drinks apple cider' => 'hot apple cider packets', 'hot drinks black coffee' => 'nestle coffee',
            'hot drinks french vanilla' => 'nestle french van', 'hot drinks hot choc' => 'nestle hot coco',
            'food cheese cup' => 'cheese', 'food pretzel' => 'pretzels plain',
            'candy lg charleston chews' => 'charleston chew',
        ];
        if (isset($aliases[$key])) $key = $aliases[$key];
        $preferred = [
            'charleston chew' => 'Burkes', 'charleston chews' => 'Burkes', 'sugar babies' => 'Burkes',
            'btm cups 44' => 'Pepsi Co', 'lg cups 32' => 'Pepsi Co', 'med cups 24' => 'Pepsi Co', 'sm cups 16' => 'Pepsi Co',
            'btm lids 44' => 'Pepsi Co', 'large lids 32' => 'Pepsi Co', 'med lids 24' => 'Pepsi Co', 'sm lids 16' => 'Pepsi Co',
            'pretzels plain' => 'Vistar', 'pretzels cinn' => 'Vistar', 'cheese' => 'Vistar',
            'icing cup' => 'Vistar', 'popcorn seed' => 'Vistar', 'mushroom corn' => 'Vistar',
            'buncha crunch' => 'Vistar', 'cookie dough bites' => 'Vistar', 'lifesaver gummies' => 'Vistar',
            'm&m original' => 'Vistar', 'm&m peanut' => 'Vistar', 'skittles sour' => 'Vistar',
            'sour patch box' => 'Vistar', 'starburst theater' => 'Vistar',
        ];
        if (isset($preferred[$key])) return $preferred[$key];
        $candidates = [];
        foreach (self::groups() as $vendor => $names) {
            foreach ($names as $candidate) {
                $candidate_key = self::normalize($candidate);
                if ($candidate_key === $key || self::fuzzy_match($key, $candidate_key)) $candidates[$vendor] = true;
            }
        }
        return count($candidates) === 1 ? (string) array_key_first($candidates) : '';
    }

    private static function groups(): array {
        return [
            'Pepsi Co' => self::list('Diet Pepsi|Mountain Dew|Sobe Water|Orange Crush|Dr. Pepper|Mug RB|Starry|Pepsi|Lemonade|Ice Tea|water|BTM Lids 44|large lids 32|Med Lids 24|Sm Lids 16|BTM Cups 44|Lg Cups 32|Med Cups 24|Sm Cups 16|CO2|Vanilla|Cherry|Strawberry|Lemon'),
            'Odom' => self::list('12oz Coors Light|12oz Blue Moon|White Claw|BrBox Red Blend|BrBox Merlot|BrBox Chard|BrBox Pinot Gris|BrBox Reisling|Voodoo Ranger|Angry Orchard Crisp Apl|PB Milk Stout|Corona|Moose Drool|Guiness'),
            'Tripp' => self::list('12oz beer cups|12oz Goose IPA|12oz Shock Top|12oz Bud Light|ICE BLACK RASP 12/16 CAN|ICE BLUE RASP 12/16 CAN|ICE TRIPLE CITRUS 12/16 CAN|Kilt Lifter|12oz Twisted Tea|No-Li - Born Raised|Mac & Jack African Amber'),
            'Safeway' => self::list('Hot Dogs|Buns|Ketchup|Mustard|Wine Cups'),
            'Concession Supply' => self::list('Hybrid Popcorn Seed|star popcorn oil|BTM Eco Bags 170oz|Lg Eco bags 130oz|Med Eco bags 85oz|Sm Eco bags 46oz|Klean Sweep|Caramel Pop Glaze|Sweet Glaze|Jalapeno powder|Cheddar powder'),
            'Amazon' => self::list('Kettle Cleaner|Mop heads|Hand Soap (Bath)'),
            'Sysco' => self::list('Toilet Paper|Nestle Hot Coco|Nestle Coffee|Nestle French Van|Nestle Frothy Bev Mix|12oz Coffee cups|33 gal trash bag|12oz Coffee Lids|Napkins|GoJo Soap 7220'),
            'Cash & Carry' => self::list('Trash bags|Paper food tray|Water|Toilet paper|Paper towels|Hot Apple Cider Packets|Straws|Glass Cleaner|Simple Green|Hand Soap (Concession)|Dish Soap|Food Handler Gloves|Paper Plates'),
            'Dollar Store' => self::list('Tropical Mike & Ike|DC Raisenets|WB Skittles|light bulbs|Oven Cleaner|Kitchen Gloves|Mopping Solution|Bleach'),
            'Bills' => self::list('1|2|5|10|20|0.25|0.5'),
            'Popcorn County' => self::list('Popstar Oil|Popcorn Seed 35#'),
            'Burkes' => self::list('Other|Popcorn Seed|Flavco|Butterfinger Bites|Charleston Chew|Dots|Gobstopper|Goobers|good & plenty|Hot tamale|junior mints|M Raisenettes|M&M Original bag|M&M Peanut bag|Mike & Ikes|Milk duds|red vines|Reeses Pieces|Skittles Original|Skittles sour bag|Sourpatch Kids Bags|Starbursts|Sugar babies|twizzler chry|whoppers|Almond joy|Baby Ruth|cookies & cream|dove dark chocolate|Hershey’s Almonds|Hershey’s Milk Choc|kitkat|Milky Way|Mounds|Reese’s PB Cups|Rolo|Skittles sour|Snickers|Spree|Twix|Starburst'),
            'Vistar' => self::list('Other|Pretzels - Plain|Pretzels - Cinn|Cheese|Icing Cup|Popcorn Seed|Flavacol|Mushroom Corn|Bit o Honey|Buncha Crunch|Butterfinger Bites|Cookie Dough Bites|Dots|good & plenty|Hot tamale|Junior Mints|Lifesaver Gummies|M Raisenettes|M&M Original|M&M Peanut|Mike & Ikes|Milk Duds|Red Vine|Reeses Pieces|Skittles Original|Skittles sour|Sour Patch Box|Sourpatch Kids Bags|Starburst Theater|Swedish Fish|Sweet Tarts|twizzler chry|Warheads|Whoppers|dove dark chocolate|Almond joy|Baby Ruth|cookies & cream|Hershey’s Almonds|Hershey’s Milk Choc|kitkat|Milky Way|Mounds|Reese’s PB Cups|Rolo|Snickers|Spree|Twix|Starburst'),
        ];
    }
    private static function list(string $value): array { return array_values(array_filter(array_map('trim', explode('|', $value)), static fn($v) => $v !== '')); }
    private static function fuzzy_match(string $left, string $right): bool {
        $ignored = ['inventory', 'beer', 'candy', 'lg', 'reg', 'large', 'medium', 'small', '12oz', '12', 'oz', 'brbox', 'box', 'bag', 'bags', 'theater'];
        $a = array_values(array_diff(explode(' ', $left), $ignored)); $b = array_values(array_diff(explode(' ', $right), $ignored));
        if (!$a || !$b) return false;
        return !array_diff($a, $b) || !array_diff($b, $a);
    }
    private static function normalize(string $value): string {
        $value = str_replace(["’", "‘", '–', '—', '&'], ["'", "'", '-', '-', 'and'], strtolower(trim($value)));
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
