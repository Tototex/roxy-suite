<?php
namespace RoxyGrosses;

if (!defined('ABSPATH')) exit;

class Metadata {
  private const SEARCH_ENDPOINT = 'https://www.wikidata.org/w/api.php';
  private const ENTITY_ENDPOINT = 'https://www.wikidata.org/wiki/Special:EntityData/';
  private const WIKIPEDIA_API = 'https://en.wikipedia.org/w/api.php';
  private const CACHE_PREFIX = 'roxy_grosses_meta_';
  private const CACHE_VERSION = 'v3';

  public static function enrich_movie_row(array $row, bool $force = false): array {
    $title = trim((string) ($row['movie_title'] ?? $row['film_title'] ?? ''));
    if ($title === '') {
      return $row;
    }

    $studio = trim((string) ($row['studio'] ?? ''));
    $genre = trim((string) ($row['genre'] ?? ''));
    if (!$force && $studio !== '' && $genre !== '') {
      return $row;
    }

    $report_date = (string) ($row['report_date'] ?? '');
    $year = preg_match('/^\d{4}-\d{2}-\d{2}$/', $report_date) ? (int) substr($report_date, 0, 4) : 0;
    $metadata = self::metadata_for_movie($title, $year, $force);

    if (($force || $studio === '') && !empty($metadata['studio'])) {
      $row['studio'] = (string) $metadata['studio'];
    }
    if (($force || $genre === '') && !empty($metadata['genre'])) {
      $row['genre'] = (string) $metadata['genre'];
    }

    return $row;
  }

  public static function metadata_for_movie(string $title, int $year = 0, bool $force = false): array {
    $normalized_title = Store::normalize_title($title);
    if ($normalized_title === '') {
      return self::empty_metadata();
    }

    $cache_key = self::CACHE_PREFIX . self::CACHE_VERSION . '_' . md5($normalized_title . '|' . $year);
    $cached = $force ? false : get_transient($cache_key);
    if (!$force && is_array($cached)) {
      return wp_parse_args($cached, self::empty_metadata());
    }

    $titles_to_try = self::candidate_titles($title, $year);
    foreach ($titles_to_try as $candidate_title) {
      $override = self::known_metadata_override($candidate_title, $year);
      if (!empty($override['studio']) || !empty($override['genre'])) {
        set_transient($cache_key, $override, DAY_IN_SECONDS * 30);
        return $override;
      }
    }

    $result = self::empty_metadata();
    foreach ($titles_to_try as $candidate_title) {
      $candidate = self::lookup_from_wikidata($candidate_title, $year);
      if (!empty($candidate['studio']) || !empty($candidate['genre'])) {
        $result = $candidate;
        break;
      }
    }

    $studio_override = self::studio_override($title);
    if ($studio_override !== '') {
      $result['studio'] = $studio_override;
    }

    set_transient($cache_key, $result, DAY_IN_SECONDS * 30);
    return $result;
  }

  private static function empty_metadata(): array {
    return [
      'studio' => '',
      'genre' => '',
      'genres' => [],
      'source' => '',
      'entity_id' => '',
    ];
  }

  private static function candidate_titles(string $title, int $year = 0): array {
    $titles = [];
    $title = trim($title);
    if ($title !== '') {
      $titles[] = $title;
    }

    foreach (self::title_variants($title, $year) as $variant) {
      $titles[] = $variant;
    }

    foreach (Settings::mappings() as $mapping) {
      if (!empty($mapping['match']) && str_contains(Store::normalize_title($title), Store::normalize_title((string) $mapping['match']))) {
        $mapped_title = trim((string) ($mapping['title'] ?? ''));
        if ($mapped_title !== '') {
          $titles[] = $mapped_title;
        }
      }
    }

    return array_values(array_unique(array_filter($titles)));
  }

  private static function title_variants(string $title, int $year = 0): array {
    $variants = [];
    $title = trim($title);
    if ($title === '') {
      return [];
    }

    $variants[] = $title;

    $clean = preg_replace('/\s+/', ' ', $title);
    $clean = preg_replace('/^\d{4}\s+/', '', (string) $clean);
    $clean = preg_replace('/^\d{1,2}[\/-]\d{3,4}\s+/', '', (string) $clean);
    $clean = preg_replace('/^\d{1,2}\s*[-\/]\s*\d{1,2}(?:\s*[-\/]\s*\d{2,4})?\s*/', '', (string) $clean);
    $clean = preg_replace('/\s+\d{1,2}\s*[-\/]\s*\d{1,2}(?:\s*[-\/]\s*\d{2,4})?$/', '', (string) $clean);
    $clean = preg_replace('/^\d{1,2}[-\/]\d{1,2}\d{0,2}\s+/', '', (string) $clean);
    $clean = preg_replace('/\s+wk\d+$/i', '', (string) $clean);
    $clean = trim((string) $clean, " \t\n\r\0\x0B,.-");
    if ($clean !== '') {
      $variants[] = $clean;
    }

      $normalized = self::lookup_key($clean);
    $aliases = self::title_alias_map($year);
    if (isset($aliases[$normalized])) {
      $variants[] = $aliases[$normalized];
    }

    $with_and = preg_replace('/\s*&\s*/', ' and ', $clean);
    if ($with_and !== null && trim($with_and) !== '') {
      $variants[] = trim($with_and);
    }

    $with_amp = preg_replace('/\s+and\s+/i', ' & ', $clean);
    if ($with_amp !== null && trim($with_amp) !== '') {
      $variants[] = trim($with_amp);
    }

    return array_values(array_unique(array_filter(array_map('trim', $variants))));
  }

  private static function title_alias_map(int $year = 0): array {
    $map = [
      'avatar fire and ash' => 'Avatar: Fire and Ash',
      'avatar, fire and ash' => 'Avatar: Fire and Ash',
      'fnaf' => 'Five Nights at Freddy\'s',
      'wicked for good' => 'Wicked: For Good',
      'gabbys doll house' => 'Gabby\'s Dollhouse: The Movie',
      'gabby s doll house' => 'Gabby\'s Dollhouse: The Movie',
      'gabbys doll house the movie' => 'Gabby\'s Dollhouse: The Movie',
      'gabby s doll house the movie' => 'Gabby\'s Dollhouse: The Movie',
      'how to train your dragon' => 'How to Train Your Dragon',
      'lilo and stitch' => 'Lilo & Stitch',
      'civil war' => 'Civil War',
      'ghostbusters frozen empire' => 'Ghostbusters: Frozen Empire',
      'migration' => 'Migration',
      'barbie' => 'Barbie',
      'beauty and the beast' => 'Beauty and the Beast',
      'fall' => 'Fall',
      'gigi nate' => 'Gigi & Nate',
      'beast' => 'Beast',
      'thor love and thunder' => 'Thor: Love and Thunder',
      'dog' => 'Dog',
      'the girl who believed in miracles' => 'The Girl Who Believed in Miracles',
      'zombieland double tap' => 'Zombieland: Double Tap',
      'dora movie' => 'Dora and the Lost City of Gold',
      'men in blackinternational' => 'Men in Black: International',
      'the lion king 2019' => 'The Lion King',
      '7 12 spider man ffh' => 'Spider-Man: Far From Home',
      'spider man ffh' => 'Spider-Man: Far From Home',
      'pokemon det pikachu' => 'Pokémon Detective Pikachu',
      'dragon 3' => 'How to Train Your Dragon: The Hidden World',
      'marry poppins returns' => 'Mary Poppins Returns',
      'spiderman into the spider verse' => 'Spider-Man: Into the Spider-Verse',
      'starwars' => 'Star Wars: The Rise of Skywalker',
      'glass' => 'Glass',
      'alpha' => 'Alpha',
      'other side of heaven 2' => 'The Other Side of Heaven 2: Fire of Faith',
      'neither wolf nor dog' => 'Neither Wolf Nor Dog',
      'aqua man' => 'Aquaman',
      'fantastic beasts the crimes of grindelwald warner bros' => 'Fantastic Beasts: The Crimes of Grindelwald',
      'grinch' => 'The Grinch',
      'avengers infinity war wk 3' => 'Avengers: Infinity War',
      'avengers infinity war wk 2' => 'Avengers: Infinity War',
      'a house with a clock in its walls' => 'The House with a Clock in Its Walls',
      'christopher robin roxy' => 'Christopher Robin',
      'hotel trans 3' => 'Hotel Transylvania 3: Summer Vacation',
      'mamma mia here we go again roxy' => 'Mamma Mia! Here We Go Again',
      'incredibles 2 roxy' => 'Incredibles 2',
      'a wrinkle in time wk 2' => 'A Wrinkle in Time',
      'thor ragnorock' => 'Thor: Ragnarok',
      'solo a star wars story wk 2' => 'Solo: A Star Wars Story',
      'star wars the last jedi week 4' => 'Star Wars: The Last Jedi',
      'star wars week 3' => 'Star Wars: The Last Jedi',
      'dispicable me 3' => 'Despicable Me 3',
      'pirates of the caribean 5' => 'Pirates of the Caribbean: Dead Men Tell No Tales',
      'guardians of the galaxy vol 2' => 'Guardians of the Galaxy Vol. 2',
      'the fantastic 4 first steps' => 'The Fantastic Four: First Steps',
      'the fantastic 4, first steps' => 'The Fantastic Four: First Steps',
      'superman' => 'Superman',
      'captian america brave new world' => 'Captain America: Brave New World',
      'mufasa the lion king' => 'Mufasa: The Lion King',
      '12/624 moana 2' => 'Moana 2',
      '12 624 moana 2' => 'Moana 2',
      'moana 2' => 'Moana 2',
      '2024 red one' => 'Red One',
      'red one' => 'Red One',
      'venom til death do they part' => 'Venom: The Last Dance',
      'joker folie a deux' => 'Joker: Folie à Deux',
      'the wild robots' => 'The Wild Robot',
      'aliens romulus' => 'Alien: Romulus',
      'bettlejuice beetlejuice' => 'Beetlejuice Beetlejuice',
      'beetlejuice bettlejuice' => 'Beetlejuice Beetlejuice',
      'it ends with us' => 'It Ends with Us',
      'twister' => 'Twisters',
      'twisters' => 'Twisters',
      'dispicable me 4' => 'Despicable Me 4',
      'garfield' => 'The Garfield Movie',
      'fall guy' => 'The Fall Guy',
      'godzilla x cong' => 'Godzilla x Kong: The New Empire',
      'boys in the boat' => 'The Boys in the Boat',
      'hunger gamesballad of songbirds and snakes' => 'The Hunger Games: The Ballad of Songbirds & Snakes',
      'hunger games ballad of songbirds and snakes' => 'The Hunger Games: The Ballad of Songbirds & Snakes',
      '12 15 wonka' => 'Wonka',
      '12-15 wonka' => 'Wonka',
      'marvels' => 'The Marvels',
      'tmntmutant mayham' => 'Teenage Mutant Ninja Turtles: Mutant Mayhem',
      'tmnt mutant mayham' => 'Teenage Mutant Ninja Turtles: Mutant Mayhem',
      'oppenhiemer' => 'Oppenheimer',
      'guadians of galaxy' => 'Guardians of the Galaxy Vol. 3',
      'guardians of the galaxy vol3' => 'Guardians of the Galaxy Vol. 3',
      'guardians of the galaxy vol 3' => 'Guardians of the Galaxy Vol. 3',
      'dungeones dragons' => 'Dungeons & Dragons: Honor Among Thieves',
      'dungeones and dragons' => 'Dungeons & Dragons: Honor Among Thieves',
      'mario' => 'The Super Mario Bros. Movie',
      'shazaam' => 'Shazam! Fury of the Gods',
      'antman' => 'Ant-Man and the Wasp: Quantumania',
      'avatar' => 'Avatar: The Way of Water',
      'devotion' => 'Devotion',
      'moon fall' => 'Moonfall',
      'spiderman nwh' => 'Spider-Man: No Way Home',
      'spiderman' => 'Spider-Man: No Way Home',
      'dc super pets' => 'DC League of Super-Pets',
      'bullit train' => 'Bullet Train',
      'dr strange in the multiverse of madness' => 'Doctor Strange in the Multiverse of Madness',
      'strange world wk2' => 'Strange World',
      '8 20 free guy' => 'Free Guy',
      'free guy' => 'Free Guy',
      'mortal combat' => 'Mortal Kombat',
      'raya' => 'Raya and the Last Dragon',
      'raya the last dragon' => 'Raya and the Last Dragon',
      'war w grandpa' => 'The War with Grandpa',
      'jumanji next level' => 'Jumanji: The Next Level',
      'paw patrol' => 'PAW Patrol: The Mighty Movie',
      'indiana jones' => 'Indiana Jones and the Dial of Destiny',
      'mercy' => 'Mercy',
      'david' => 'King David',
      'elio' => 'Elio',
      'brave the dark' => 'Brave the Dark',
      'homestead' => 'Homestead',
      'iron lung' => 'Iron Lung',
    ];

    if ($year >= 2025) {
      $map['fnaf'] = 'Five Nights at Freddy\'s 2';
    }

    return $map;
  }

  private static function known_metadata_override(string $title, int $year = 0): array {
      $normalized = self::lookup_key($title);
    $map = [
      'the super mario bros movie' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family'],
      'avatar the way of water' => ['studio' => '20th Century Studios', 'genre' => 'Adventure, Action, Fantasy, Science Fiction'],
      'avatar fire and ash' => ['studio' => '20th Century Studios', 'genre' => 'Adventure, Action, Fantasy, Science Fiction'],
      'five nights at freddys' => ['studio' => 'Universal Pictures', 'genre' => 'Horror, Mystery'],
      'five nights at freddys 2' => ['studio' => 'Universal Pictures', 'genre' => 'Horror, Mystery'],
      'wicked for good' => ['studio' => 'Universal Pictures', 'genre' => 'Fantasy, Drama, Musical, Music'],
      'the fantastic four first steps' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction, Superhero'],
      'superman' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Science Fiction, Superhero'],
      'captain america brave new world' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Thriller, Science Fiction, Superhero'],
      'moana 2' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Fantasy'],
      'red one' => ['studio' => 'Amazon MGM Studios', 'genre' => 'Adventure, Action, Comedy, Fantasy, Holiday'],
      'venom the last dance' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Action, Thriller, Science Fiction, Superhero'],
      'joker folie a deux' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Drama, Thriller, Musical, Music, Crime'],
      'the wild robot' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Children, Family, Science Fiction'],
      'alien romulus' => ['studio' => '20th Century Studios', 'genre' => 'Horror, Science Fiction'],
      'beetlejuice beetlejuice' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Comedy, Fantasy, Horror'],
      'it ends with us' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Drama, Romance'],
      'twisters' => ['studio' => 'Universal Pictures', 'genre' => 'Adventure, Action, Drama'],
      'deadpool and wolverine' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Action, Comedy, Science Fiction, Superhero'],
      'despicable me 4' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'the garfield movie' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'the fall guy' => ['studio' => 'Universal Pictures', 'genre' => 'Action, Comedy, Drama'],
      'godzilla x kong the new empire' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Science Fiction'],
      'the boys in the boat' => ['studio' => 'Amazon MGM Studios', 'genre' => 'Drama, Biography'],
      'the hunger games the ballad of songbirds snakes' => ['studio' => 'Lionsgate Films', 'genre' => 'Adventure, Action, Drama, Science Fiction'],
      'the hunger games the ballad of songbirds and snakes' => ['studio' => 'Lionsgate Films', 'genre' => 'Adventure, Action, Drama, Science Fiction'],
      'wonka' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Comedy, Children, Family, Fantasy, Musical'],
      'the marvels' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Science Fiction, Superhero'],
      'teenage mutant ninja turtles mutant mayhem' => ['studio' => 'Paramount Pictures', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Science Fiction'],
      'oppenheimer' => ['studio' => 'Universal Pictures', 'genre' => 'Drama, Biography'],
      'mission impossible dead reckoning part one' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Thriller'],
      'indiana jones and the dial of destiny' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action'],
      'transformers rise of the beasts' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'spider man across the spider verse' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Science Fiction, Superhero'],
      'guardians of the galaxy vol 3' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Science Fiction, Superhero'],
      'dungeons dragons honor among thieves' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Comedy, Fantasy'],
      'dungeons and dragons honor among thieves' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Comedy, Fantasy'],
      'shazam fury of the gods' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Comedy, Fantasy, Science Fiction, Superhero'],
      'ant man and the wasp quantumania' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Science Fiction, Superhero'],
      'devotion' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Action, Drama, War, Biography'],
      'moonfall' => ['studio' => 'Lionsgate Films', 'genre' => 'Adventure, Action, Science Fiction'],
      'lyle lyle crocodile' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Comedy, Children, Family, Fantasy, Musical'],
      'dc league of super pets' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Superhero'],
      'bullet train' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Action, Comedy, Thriller'],
      'doctor strange in the multiverse of madness' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Action, Fantasy, Horror, Science Fiction, Superhero'],
      'strange world' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Animation, Adventure, Action, Children, Family, Science Fiction'],
      'spider man no way home' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Action, Fantasy, Science Fiction, Superhero'],
      'free guy' => ['studio' => '20th Century Studios', 'genre' => 'Action, Comedy, Science Fiction'],
      'mortal kombat' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Action, Fantasy'],
      'raya and the last dragon' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Animation, Adventure, Action, Children, Family, Fantasy'],
      'croods a new age' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Fantasy'],
      'the war with grandpa' => ['studio' => '101 Studios', 'genre' => 'Comedy, Children, Family'],
      'unhinged' => ['studio' => 'Solstice Studios', 'genre' => 'Thriller'],
      'jumanji the next level' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Action, Comedy, Fantasy'],
      'doolittle' => ['studio' => 'Universal Pictures', 'genre' => 'Adventure, Comedy, Children, Family, Fantasy'],
      'star wars' => ['studio' => 'Lucasfilm', 'genre' => 'Adventure, Action, Science Fiction'],
      'mercy' => ['studio' => 'Amazon MGM Studios', 'genre' => 'Science Fiction, Thriller'],
      'elio' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Science Fiction'],
      'brave the dark' => ['studio' => 'Angel Studios', 'genre' => 'Drama'],
      'homestead' => ['studio' => 'Angel Studios', 'genre' => 'Drama'],
      'king david' => ['studio' => 'Angel Studios', 'genre' => 'Adventure, Drama, Biography, Faith'],
      'how to train your dragon' => ['studio' => 'Universal Pictures', 'genre' => 'Adventure, Action, Children, Family, Fantasy'],
      'lilo and stitch' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Children, Family, Science Fiction'],
      'civil war' => ['studio' => 'A24', 'genre' => 'Action, Thriller, War'],
      'ghostbusters frozen empire' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Action, Comedy, Fantasy, Science Fiction'],
      'heaven' => ['studio' => 'Bridge & Acorn Entertainment', 'genre' => 'Drama, Faith, Family'],
      'iron lung' => ['studio' => 'Markiplier Studios', 'genre' => 'Science Fiction, Horror'],
      'the last class' => ['studio' => 'CoffeeKlatch Productions', 'genre' => 'Documentary'],
      'migration' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'barbie' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Comedy, Fantasy'],
      'beauty and the beast' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Children, Family, Fantasy, Musical'],
      'ferris bueller s day off' => ['studio' => 'Paramount Pictures', 'genre' => 'Comedy, Children, Family'],
      'true grit 1969' => ['studio' => 'Paramount Pictures', 'genre' => 'Western, Adventure, Drama'],
      'despicable me' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'pretty in pink' => ['studio' => 'Paramount Pictures', 'genre' => 'Comedy, Drama, Romance'],
      'the karate kid' => ['studio' => 'Columbia Pictures', 'genre' => 'Drama, Children, Family, Sports'],
      'fall' => ['studio' => 'Lionsgate Films', 'genre' => 'Thriller'],
      'gigi and nate' => ['studio' => 'Roadside Attractions', 'genre' => 'Drama'],
      'gabby s dollhouse the movie' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Fantasy'],
      'beast' => ['studio' => 'Universal Pictures', 'genre' => 'Adventure, Action, Thriller'],
      'thor love and thunder' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Fantasy, Science Fiction, Superhero'],
      'dog' => ['studio' => 'United Artists Releasing', 'genre' => 'Comedy, Drama'],
      'the girl who believed in miracles' => ['studio' => 'Atlas Distribution Company', 'genre' => 'Drama, Faith'],
      'springsteen deliver me from nowhere' => ['studio' => '20th Century Studios', 'genre' => 'Drama, Biography, Music'],
      'gabby s dollhouse the movie' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Fantasy'],
      'tron ares' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'demon slayer infinity castle' => ['studio' => 'Crunchyroll', 'genre' => 'Animation, Adventure, Action, Fantasy'],
      'zombieland double tap' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Action, Comedy, Horror'],
      'dora and the lost city of gold' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Comedy, Children, Family'],
      'men in black international' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Action, Comedy, Science Fiction'],
      'the lion king' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Drama, Children, Family, Musical'],
      'spider man far from home' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Action, Comedy, Science Fiction, Superhero'],
      'pok mon detective pikachu' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Comedy, Children, Family, Mystery'],
      'how to train your dragon the hidden world' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Fantasy'],
      'mary poppins returns' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Comedy, Children, Family, Fantasy, Musical'],
      'spider man into the spider verse' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Science Fiction, Superhero'],
      'aquaman' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Fantasy, Science Fiction, Superhero'],
      'glass' => ['studio' => 'Universal Pictures', 'genre' => 'Drama, Horror, Thriller'],
      'alpha' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Adventure, Drama'],
      'the other side of heaven 2 fire of faith' => ['studio' => 'Purdie Distribution', 'genre' => 'Adventure, Drama, Biography, Faith'],
      'neither wolf nor dog' => ['studio' => 'Wolfe Releasing', 'genre' => 'Adventure, Drama'],
      'fantastic beasts the crimes of grindelwald' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Adventure, Action, Family, Fantasy'],
      'the grinch' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Comedy, Children, Family, Fantasy, Holiday'],
      'avengers infinity war' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction, Superhero'],
      'the house with a clock in its walls' => ['studio' => 'Universal Pictures', 'genre' => 'Comedy, Children, Family, Fantasy, Horror, Mystery'],
      'christopher robin' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Comedy, Children, Family, Fantasy'],
      'hotel transylvania 3 summer vacation' => ['studio' => 'Sony Pictures Releasing', 'genre' => 'Animation, Adventure, Comedy, Children, Family, Fantasy'],
      'mamma mia here we go again' => ['studio' => 'Universal Pictures', 'genre' => 'Comedy, Drama, Romance, Musical, Music'],
      'incredibles 2' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Animation, Adventure, Action, Comedy, Children, Family, Science Fiction, Superhero'],
      'a wrinkle in time' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Children, Family, Fantasy, Science Fiction'],
      'ferdinand' => ['studio' => '20th Century Studios', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'thor ragnarok' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Fantasy, Science Fiction, Superhero'],
      'solo a star wars story' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'star wars the last jedi' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'star wars the rise of skywalker' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'wonder' => ['studio' => 'Lionsgate Films', 'genre' => 'Drama, Children, Family'],
      'it' => ['studio' => 'Warner Bros. Entertainment', 'genre' => 'Drama, Horror, Thriller'],
      'transformers 5 the last night' => ['studio' => 'Paramount Pictures', 'genre' => 'Adventure, Action, Science Fiction'],
      'despicable me 3' => ['studio' => 'Universal Pictures', 'genre' => 'Animation, Adventure, Comedy, Children, Family'],
      'pirates of the caribbean dead men tell no tales' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Fantasy'],
      'guardians of the galaxy vol 2' => ['studio' => 'Walt Disney Studios Motion Pictures', 'genre' => 'Adventure, Action, Comedy, Science Fiction, Superhero'],
    ];

    if (isset($map[$normalized])) {
      return [
        'studio' => $map[$normalized]['studio'],
        'genre' => $map[$normalized]['genre'],
        'genres' => array_map('trim', explode(',', $map[$normalized]['genre'])),
        'source' => 'override',
        'entity_id' => '',
      ];
    }

    return self::empty_metadata();
  }

  private static function studio_override(string $title): string {
    $normalized = self::lookup_key($title);
    foreach (Settings::studio_mappings() as $mapping) {
      $match = self::lookup_key((string) ($mapping['match'] ?? ''));
      if ($match !== '' && str_contains($normalized, $match)) {
        return trim((string) ($mapping['studio'] ?? ''));
      }
    }
    return '';
  }

  private static function lookup_from_wikidata(string $title, int $year = 0): array {
    $search_results = self::search_wikidata($title);
    if (!$search_results) {
      return self::empty_metadata();
    }

    $best = null;
    $best_score = PHP_INT_MIN;
    foreach (array_slice($search_results, 0, 5) as $search_result) {
      $entity_id = (string) ($search_result['id'] ?? '');
      if ($entity_id === '') {
        continue;
      }

      $entity = self::fetch_entity($entity_id);
      if (!$entity) {
        continue;
      }

      $score = self::score_entity($entity, $title, $year, $search_result);
      if ($score > $best_score) {
        $best_score = $score;
        $best = [
          'entity' => $entity,
          'entity_id' => $entity_id,
        ];
      }
    }

    if (!$best || $best_score < 20) {
      return self::empty_metadata();
    }

    $entity = (array) ($best['entity'] ?? []);
    $genre_ids = self::claim_entity_ids($entity, 'P136');
    $studio_ids = self::claim_entity_ids($entity, 'P750');
    if (!$studio_ids) {
      $studio_ids = self::claim_entity_ids($entity, 'P272');
    }
    $labels = self::fetch_entity_labels(array_merge($genre_ids, $studio_ids));

    $wikidata_genres = self::normalize_genres(self::labels_for_ids($genre_ids, $labels));
    $category_genres = self::normalize_category_genres(self::wikipedia_category_genres($entity));
    $normalized_genres = self::merge_genres($wikidata_genres, $category_genres);

    return [
      'studio' => self::normalize_studio(self::first_label_for_ids($studio_ids, $labels)),
      'genre' => implode(', ', $normalized_genres),
      'genres' => $normalized_genres,
      'source' => 'wikidata',
      'entity_id' => (string) ($best['entity_id'] ?? ''),
    ];
  }

  private static function search_wikidata(string $title): array {
    $url = add_query_arg([
      'action' => 'wbsearchentities',
      'search' => $title,
      'language' => 'en',
      'format' => 'json',
      'type' => 'item',
      'limit' => 8,
    ], self::SEARCH_ENDPOINT);

    $data = self::request_json($url);
    $results = is_array($data['search'] ?? null) ? $data['search'] : [];
    return array_values(array_filter($results, static function (array $item): bool {
      $description = strtolower(trim((string) ($item['description'] ?? '')));
      return $description === '' || str_contains($description, 'film') || str_contains($description, 'movie') || str_contains($description, 'documentary');
    }));
  }

  private static function fetch_entity(string $entity_id): array {
    $url = self::ENTITY_ENDPOINT . rawurlencode($entity_id) . '.json';
    $data = self::request_json($url);
    return is_array($data['entities'][$entity_id] ?? null) ? $data['entities'][$entity_id] : [];
  }

  private static function fetch_entity_labels(array $entity_ids): array {
    $entity_ids = array_values(array_unique(array_filter(array_map('strval', $entity_ids))));
    if (!$entity_ids) {
      return [];
    }

    $url = add_query_arg([
      'action' => 'wbgetentities',
      'ids' => implode('|', $entity_ids),
      'languages' => 'en',
      'props' => 'labels',
      'format' => 'json',
    ], self::SEARCH_ENDPOINT);

    $data = self::request_json($url);
    return is_array($data['entities'] ?? null) ? $data['entities'] : [];
  }

  private static function wikipedia_category_genres(array $entity): array {
    $page_title = trim((string) ($entity['sitelinks']['enwiki']['title'] ?? ''));
    if ($page_title === '') {
      return [];
    }

    $url = add_query_arg([
      'action' => 'query',
      'titles' => $page_title,
      'prop' => 'categories',
      'cllimit' => 'max',
      'format' => 'json',
    ], self::WIKIPEDIA_API);

    $data = self::request_json($url);
    $pages = is_array($data['query']['pages'] ?? null) ? $data['query']['pages'] : [];
    $categories = [];
    foreach ($pages as $page) {
      foreach ((array) ($page['categories'] ?? []) as $category) {
        $title = trim((string) ($category['title'] ?? ''));
        if ($title !== '') {
          $categories[] = preg_replace('/^Category:/i', '', $title);
        }
      }
    }

    return array_values(array_unique(array_filter($categories)));
  }

  private static function request_json(string $url): array {
    $response = wp_remote_get($url, [
      'timeout' => 15,
      'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'RoxySuite/1.0 (' . home_url('/') . ')',
      ],
    ]);

    if (is_wp_error($response)) {
      return [];
    }

    $body = wp_remote_retrieve_body($response);
    if (!is_string($body) || $body === '') {
      return [];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function score_entity(array $entity, string $title, int $year, array $search_result): int {
    $score = 0;
    $normalized_title = Store::normalize_title($title);
    $label = Store::normalize_title((string) ($entity['labels']['en']['value'] ?? $search_result['label'] ?? ''));
    if ($label === $normalized_title) {
      $score += 30;
    } elseif ($label !== '' && str_contains($label, $normalized_title)) {
      $score += 15;
    }

    foreach ((array) ($entity['aliases']['en'] ?? []) as $alias) {
      $alias_value = Store::normalize_title((string) ($alias['value'] ?? ''));
      if ($alias_value === $normalized_title) {
        $score += 10;
        break;
      }
    }

    $description = strtolower(trim((string) ($entity['descriptions']['en']['value'] ?? $search_result['description'] ?? '')));
    if ($description !== '' && (str_contains($description, 'film') || str_contains($description, 'movie') || str_contains($description, 'documentary'))) {
      $score += 10;
    }

    $release_year = self::entity_release_year($entity);
    if ($year > 0 && $release_year > 0) {
      if ($release_year === $year) {
        $score += 40;
      } elseif (abs($release_year - $year) === 1) {
        $score += 20;
      } elseif (abs($release_year - $year) <= 2) {
        $score += 10;
      } else {
        $score -= 20;
      }
    }

    if (self::claim_entity_ids($entity, 'P136')) {
      $score += 5;
    }
    if (self::claim_entity_ids($entity, 'P750') || self::claim_entity_ids($entity, 'P272')) {
      $score += 5;
    }

    return $score;
  }

  private static function entity_release_year(array $entity): int {
    $claims = (array) ($entity['claims']['P577'] ?? []);
    foreach ($claims as $claim) {
      $timestamp = (string) ($claim['mainsnak']['datavalue']['value']['time'] ?? '');
      if (preg_match('/^[+-](\d{4})-/', $timestamp, $matches)) {
        return (int) $matches[1];
      }
    }
    return 0;
  }

  private static function claim_entity_ids(array $entity, string $property): array {
    $ids = [];
    foreach ((array) ($entity['claims'][$property] ?? []) as $claim) {
      $id = (string) ($claim['mainsnak']['datavalue']['value']['id'] ?? '');
      if ($id !== '') {
        $ids[] = $id;
      }
    }
    return array_values(array_unique($ids));
  }

  private static function first_label_for_ids(array $entity_ids, array $labels): string {
    foreach ($entity_ids as $entity_id) {
      $value = trim((string) ($labels[$entity_id]['labels']['en']['value'] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

  private static function labels_for_ids(array $entity_ids, array $labels): array {
    $results = [];
    foreach ($entity_ids as $entity_id) {
      $value = trim((string) ($labels[$entity_id]['labels']['en']['value'] ?? ''));
      if ($value !== '') {
        $results[] = $value;
      }
    }
    return array_values(array_unique($results));
  }

  private static function normalize_studio(string $studio): string {
    return trim($studio);
  }

  private static function normalize_genres(array $raw_genres): array {
    $mapped = [];
    foreach ($raw_genres as $raw_genre) {
      $normalized = Store::normalize_title((string) $raw_genre);
      if ($normalized === '') {
        continue;
      }

      $genre_map = [
        'animation' => 'Animation',
        'animated' => 'Animation',
        'computer animated' => 'Animation',
        'cgi' => 'Animation',
        'cartoon' => 'Animation',
        'adventure' => 'Adventure',
        'action' => 'Action',
        'comedy' => 'Comedy',
        'family' => 'Family',
        'children' => 'Children',
        'childrens' => 'Children',
        'kids' => 'Children',
        'teen' => 'Children',
        'fantasy' => 'Fantasy',
        'drama' => 'Drama',
        'horror' => 'Horror',
        'thriller' => 'Thriller',
        'romance' => 'Romance',
        'science fiction' => 'Science Fiction',
        'sci fi' => 'Science Fiction',
        'space' => 'Science Fiction',
        'documentary' => 'Documentary',
        'musical' => 'Musical',
        'music' => 'Music',
        'crime' => 'Crime',
        'mystery' => 'Mystery',
        'war' => 'War',
        'western' => 'Western',
        'holiday' => 'Holiday',
        'christmas' => 'Holiday',
        'superhero' => 'Superhero',
        'biographical' => 'Biography',
        'biography' => 'Biography',
        'faith' => 'Faith',
        'christian' => 'Faith',
      ];

      foreach ($genre_map as $needle => $label) {
        if (self::contains_genre_phrase($normalized, $needle)) {
          $mapped[$label] = true;
        }
      }
    }

    if (!empty($mapped['Animation']) || !empty($mapped['Children'])) {
      $mapped['Family'] = true;
    }

    $ordered_labels = [
      'Animation', 'Adventure', 'Action', 'Comedy', 'Children', 'Family',
      'Fantasy', 'Drama', 'Horror', 'Thriller', 'Romance', 'Science Fiction',
      'Documentary', 'Musical', 'Music', 'Crime', 'Mystery', 'War', 'Western',
      'Faith',
      'Holiday', 'Superhero', 'Biography',
    ];

    $results = [];
    foreach ($ordered_labels as $label) {
      if (!empty($mapped[$label])) {
        $results[] = $label;
      }
    }

    return $results;
  }

  private static function normalize_category_genres(array $raw_categories): array {
    $allowed = [];
    foreach ($raw_categories as $raw_category) {
      $normalized = Store::normalize_title((string) $raw_category);
      if ($normalized === '') {
        continue;
      }

      if (self::contains_genre_phrase($normalized, 'animation') || self::contains_genre_phrase($normalized, 'animated') || self::contains_genre_phrase($normalized, 'cartoon')) {
        $allowed['Animation'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'children') || self::contains_genre_phrase($normalized, 'childrens') || self::contains_genre_phrase($normalized, 'kids') || self::contains_genre_phrase($normalized, 'teen')) {
        $allowed['Children'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'family')) {
        $allowed['Family'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'christmas') || self::contains_genre_phrase($normalized, 'holiday')) {
        $allowed['Holiday'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'christian') || self::contains_genre_phrase($normalized, 'faith')) {
        $allowed['Faith'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'superhero') || self::contains_genre_phrase($normalized, 'comic book')) {
        $allowed['Superhero'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'biographical') || self::contains_genre_phrase($normalized, 'biography') || self::contains_genre_phrase($normalized, 'based on a true story')) {
        $allowed['Biography'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'documentary')) {
        $allowed['Documentary'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'musical')) {
        $allowed['Musical'] = true;
      }
      if (self::contains_genre_phrase($normalized, 'music')) {
        $allowed['Music'] = true;
      }
    }

    if (!empty($allowed['Animation']) || !empty($allowed['Children'])) {
      $allowed['Family'] = true;
    }

    return self::ordered_genres(array_keys($allowed));
  }

  private static function merge_genres(array $primary, array $secondary): array {
    $labels = [];
    foreach (array_merge($primary, $secondary) as $genre) {
      $labels[(string) $genre] = true;
    }
    if (!empty($labels['Animation']) || !empty($labels['Children'])) {
      $labels['Family'] = true;
    }
    return self::ordered_genres(array_keys($labels));
  }

  private static function ordered_genres(array $genres): array {
    $lookup = [];
    foreach ($genres as $genre) {
      $genre = trim((string) $genre);
      if ($genre !== '') {
        $lookup[$genre] = true;
      }
    }

    $ordered_labels = [
      'Animation', 'Adventure', 'Action', 'Comedy', 'Children', 'Family',
      'Fantasy', 'Drama', 'Horror', 'Thriller', 'Romance', 'Science Fiction',
      'Documentary', 'Musical', 'Music', 'Crime', 'Mystery', 'War', 'Western',
      'Faith', 'Holiday', 'Superhero', 'Biography',
    ];

    $results = [];
    foreach ($ordered_labels as $label) {
      if (!empty($lookup[$label])) {
        $results[] = $label;
      }
    }
    return $results;
  }

  private static function contains_genre_phrase(string $haystack, string $needle): bool {
    $needle = Store::normalize_title($needle);
    if ($needle === '' || $haystack === '') {
      return false;
    }

    $pattern = '/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/i';
    return preg_match($pattern, $haystack) === 1;
  }

  private static function lookup_key(string $value): string {
    $value = strtolower(trim($value));
    $value = str_replace(['â€”', 'â€“', '&'], ['-', '-', ' and '], $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
  }
}
