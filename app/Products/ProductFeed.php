<?php

namespace App\Products;

use App\Models\ClientProduct;
use DOMElement;
use Generator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use XMLReader;

/**
 * A shop's product feed: the XML file an e-shop makes for Skroutz, BestPrice, Google Shopping or a Facebook catalog, given
 * by its link (Panos, 2026-11-10: "the most correct way to show products in posts… an xml with products"). The shop
 * publishes it for exactly this reuse, so nothing is crawled. download() fetches it to a temporary file (http or https,
 * public hosts only, every redirect checked, MAX_BYTES at most); parse() streams it, record by record, into the
 * client_products fields, whatever the dialect: a <product> (Skroutz, BestPrice), an RSS <item> or an Atom <entry>
 * (Google Merchant, Facebook) with or without the g: namespace.
 */
final class ProductFeed
{
    public const MAX_BYTES = 200 * 1024 * 1024;

    /** The most products kept per client: a shop's feed, not a marketplace's. */
    public const MAX_PRODUCTS = 20000;

    /** The elements one product sits in, and the name the Files tab gives each dialect. */
    public const RECORDS = ['product' => 'Skroutz / BestPrice XML', 'item' => 'Google / Facebook RSS', 'entry' => 'Google Atom'];

    /** The children a field may come from, in order of preference, by local name in lowercase (g:price is price). */
    private const FIELDS = [
        'id' => ['id', 'productid', 'product_id', 'uniqueid', 'unique_id', 'sku', 'mpn', 'code'],
        'name' => ['name', 'title', 'productname', 'product_name'],
        'url' => ['link', 'url', 'producturl', 'product_url', 'productlink', 'product_link'],
        'price' => ['price_with_vat', 'pricewithvat', 'price', 'final_price', 'retail_price', 'regular_price'],
        'sale_price' => ['sale_price', 'saleprice', 'special_price', 'offer_price', 'discount_price'],
        'category' => ['category_path', 'categorypath', 'category', 'product_type', 'categories', 'category_name', 'google_product_category'],
        'brand' => ['brand', 'manufacturer'],
        'image' => ['image', 'image_link', 'imagelink', 'imageurl', 'image_url', 'mainimage', 'thumbnail'],
        'description' => ['description', 'short_description', 'shortdescription'],
    ];

    /** Stock fields, the yes/no ones first, then counts, then the availability text. */
    private const STOCK = ['instock', 'in_stock', 'stock', 'quantity', 'stock_quantity', 'availability'];

    public const TOO_BIG = 'The feed is bigger than 200 MB.';

    /** How a host name becomes addresses; the tests swap it so they need no DNS. */
    public static $resolver = null;

    /**
     * The address checked as the app will fetch it: http or https, a host that resolves only to public addresses (never
     * the server itself or its network).
     *
     * @throws RuntimeException naming what is wrong, for the Files tab
     */
    public static function guard(string $url): void
    {
        $parts = parse_url(trim($url));
        throw_unless(is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '', new RuntimeException('Paste the full link of the feed, starting with https://'));
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ((self::$resolver ?? fn (string $h) => gethostbynamel($h))($host) ?: []);
        throw_if($ips === [], new RuntimeException("The address {$host} could not be found."));
        foreach ($ips as $ip) {
            throw_unless(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE), new RuntimeException("The address {$host} is not a public shop."));
        }
    }

    /**
     * The feed in a temporary file, for parse(); the caller deletes it.
     *
     * @throws RuntimeException when the address is refused, the shop does not answer or the file is too big
     */
    public static function download(string $url): string
    {
        self::guard($url);
        $path = tempnam(sys_get_temp_dir(), 'feed');
        try {
            $response = Http::timeout(300)->connectTimeout(20)->withUserAgent('SocialOps product feed reader')->sink($path)->withOptions([
                'allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https'], 'on_redirect' => fn ($request, $response, $uri) => self::guard((string) $uri)],
                'progress' => function ($total, $done) {
                    throw_if($total > self::MAX_BYTES || $done > self::MAX_BYTES, new RuntimeException(self::TOO_BIG));
                },
            ])->get($url);
        } catch (Throwable $e) {
            @unlink($path);
            throw new RuntimeException(str_contains($e->getMessage(), self::TOO_BIG) ? self::TOO_BIG : 'The shop did not hand over the feed: '.$e->getMessage());
        }
        if (! $response->successful()) {
            @unlink($path);
            throw new RuntimeException("The shop answered {$response->status()} for the feed's link.");
        }
        clearstatcache(true, $path);
        if (filesize($path) > self::MAX_BYTES) {
            @unlink($path);
            throw new RuntimeException(self::TOO_BIG);
        }

        return $path;
    }

    /**
     * The products of a feed file, one by one, with `record` (product, item or entry). A record without a name or an
     * http(s) link is skipped. External entities are never loaded.
     *
     * @return Generator<int, array{record: string, external_id: string, name: string, url: string, price: ?string, sale_price: ?string, category: ?string, brand: ?string, image: ?string, in_stock: ?bool, description: ?string}>
     *
     * @throws RuntimeException when the file is no XML
     */
    public static function parse(string $path): Generator
    {
        $reader = new XMLReader;
        throw_unless(@$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE), new RuntimeException('The file could not be opened as XML.'));
        $previous = libxml_use_internal_errors(true);
        $found = false;
        try {
            $ok = $reader->read();
            while ($ok) {
                $record = strtolower((string) $reader->localName);
                if ($reader->nodeType === XMLReader::ELEMENT && isset(self::RECORDS[$record]) && ($node = $reader->expand()) instanceof DOMElement) {
                    if ($product = self::product($node, $record)) {
                        $found = true;
                        yield $product;
                    }
                    $ok = $reader->next();

                    continue;
                }
                $ok = $reader->read();
            }
            $error = libxml_get_last_error();
            if (! $found && $error && $error->level === LIBXML_ERR_FATAL) {
                throw new RuntimeException('The file is not XML: '.trim($error->message));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** One record's children by local name in lowercase, the first of each, then the fields. */
    private static function product(DOMElement $node, string $record): ?array
    {
        $values = [];
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            $key = strtolower($child->localName);
            $value = trim($child->textContent);
            if ($value === '' && $child->hasAttribute('href')) { // Atom: <link href="…"/>
                $value = trim($child->getAttribute('href'));
            }
            if ($value !== '' && ! isset($values[$key])) {
                $values[$key] = $value;
            }
        }
        $pick = function (string $field) use ($values): ?string {
            foreach (self::FIELDS[$field] as $key) {
                if (isset($values[$key])) {
                    return $values[$key];
                }
            }

            return null;
        };

        $name = self::clean($pick('name'), 500);
        $url = trim((string) $pick('url'));
        if ($name === null || ! preg_match('~^https?://~i', $url)) {
            return null;
        }
        $description = $pick('description');
        $image = trim((string) $pick('image'));

        return [
            'record' => $record,
            'external_id' => mb_substr($pick('id') ?? sha1($url), 0, 191),
            'name' => $name,
            'url' => $url,
            'price' => self::clean($pick('price'), 40),
            'sale_price' => self::clean($pick('sale_price'), 40),
            'category' => self::clean($pick('category'), 500),
            'brand' => self::clean($pick('brand'), 191),
            'image' => preg_match('~^https?://~i', $image) ? $image : null,
            'in_stock' => self::stock($values),
            'description' => self::clean($description === null ? null : strip_tags(html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 500),
        ];
    }

    /** Yes, no, or null when the feed does not say: an explicit flag first, then a count, then the availability text. */
    public static function stock(array $values): ?bool
    {
        foreach (self::STOCK as $key) {
            if (! isset($values[$key])) {
                continue;
            }
            $value = ClientProduct::fold($values[$key]);
            if (in_array($value, ['y', 'yes', 'true', '1', 'ναι'], true)) {
                return true;
            }
            if (in_array($value, ['n', 'no', 'false', '0', 'οχι'], true)) {
                return false;
            }
            if (is_numeric($value)) {
                return (float) $value > 0;
            }
            foreach (['out of stock', 'out_of_stock', 'outofstock', 'sold out', 'discontinued', 'preorder', 'pre-order', 'backorder', 'εξαντλ', 'μη διαθεσιμ', 'δεν ειναι διαθεσιμ', 'προπαραγγελ', 'κατοπιν παραγγελιασ'] as $no) {
                if (str_contains($value, $no)) {
                    return false;
                }
            }
            foreach (['in stock', 'in_stock', 'instock', 'available', 'διαθεσιμ', 'αμεσα', 'παραδοση', 'ετοιμο'] as $yes) {
                if (str_contains($value, $yes)) {
                    return true;
                }
            }
        }

        return null;
    }

    /** One line of plain text, at most $max characters; null when nothing is left. */
    private static function clean(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text)));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
