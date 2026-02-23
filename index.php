<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

session_start();

$GLOBALS['last_pdf_text'] = '';

function polishMonthToNumber(string $month): ?string
{
    $month = mb_strtolower($month, 'UTF-8');
    $month = rtrim($month, '.');

    $map = [
        'sty' => '01',
        'stycznia' => '01',
        'lut' => '02',
        'lutego' => '02',
        'mar' => '03',
        'marca' => '03',
        'kwi' => '04',
        'kwietnia' => '04',
        'maj' => '05',
        'cze' => '06',
        'czerwca' => '06',
        'lip' => '07',
        'lipca' => '07',
        'sie' => '08',
        'sierpnia' => '08',
        'wrz' => '09',
        'września' => '09',
        'wrzesnia' => '09',
        'paź' => '10',
        'paz' => '10',
        'października' => '10',
        'pazdziernika' => '10',
        'lis' => '11',
        'listopada' => '11',
        'gru' => '12',
        'grudnia' => '12',
    ];

    return $map[$month] ?? null;
}

function ocrPdfToText(string $filePath): string
{
    $tmpBase = sys_get_temp_dir() . '/pdf2jpk_' . bin2hex(random_bytes(4));

    if (!@mkdir($tmpBase) && !is_dir($tmpBase)) {
        return '';
    }

    $prefix = $tmpBase . '/page';
    $cmd = 'pdftoppm -png ' . escapeshellarg($filePath) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
    shell_exec($cmd);

    $images = glob($tmpBase . '/page-*.png') ?: [];
    sort($images);

    $text = '';

    foreach ($images as $image) {
        $cmdTesseract = 'tesseract ' . escapeshellarg($image) . ' - -l pol+eng 2>/dev/null';
        $out = shell_exec($cmdTesseract);

        if (is_string($out)) {
            $text .= "\n" . $out;
        }
    }

    foreach (glob($tmpBase . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($tmpBase);

    return trim($text);
}

function parseInvoicesFromPdf(string $filePath, string $companyNip): array
{
    $empty = [
        'invoice_number' => null,
        'issue_date' => null,
        'sell_date' => null,
        'buyer_nip' => null,
        'seller_nip' => $companyNip,
        'net_amount' => null,
        'vat_rate' => null,
        'vat_amount' => null,
        'gross_amount' => null,
        'currency' => 'PLN',
        'buyer_name' => null,
        'seller_name' => null,
    ];

    if (!is_readable($filePath)) {
        return [];
    }

    if (!class_exists('Smalot\\PdfParser\\Parser')) {
        return [];
    }

    $parserClass = 'Smalot\\PdfParser\\Parser';
    $parser = new $parserClass();
    $pdf = $parser->parseFile($filePath);
    $text = $pdf->getText();

    if (trim($text) === '') {
        $text = ocrPdfToText($filePath);
    }

    $GLOBALS['last_pdf_text'] = $text;

    if ($text === '') {
        return [];
    }

    $parts = preg_split('/(?=Wartość\s+netto\s+[0-9]+,[0-9]{2}\s+PLN)/u', $text, -1, PREG_SPLIT_NO_EMPTY);

    $invoices = [];

    foreach ($parts as $part) {
        $data = extractInvoiceDataFromText($part, $companyNip);

        if (!empty($data['invoice_number'])) {
            foreach ($empty as $key => $value) {
                if (!array_key_exists($key, $data) || $data[$key] === null) {
                    $data[$key] = $value;
                }
            }

            $invoices[] = $data;
        }
    }

    if (empty($invoices)) {
        $data = extractInvoiceDataFromText($text, $companyNip);

        if (!empty($data['invoice_number'])) {
            foreach ($empty as $key => $value) {
                if (!array_key_exists($key, $data) || $data[$key] === null) {
                    $data[$key] = $value;
                }
            }

            $invoices[] = $data;
        }
    }

    return $invoices;
}

function extractInvoiceDataFromText(string $text, string $companyNip): array
{
    $data = [
        'invoice_number' => null,
        'issue_date' => null,
        'sell_date' => null,
        'buyer_nip' => null,
        'seller_nip' => null,
        'net_amount' => null,
        'vat_rate' => null,
        'vat_amount' => null,
        'gross_amount' => null,
        'currency' => 'PLN',
        'buyer_name' => null,
        'seller_name' => null,
    ];

    if (preg_match('/Faktura\s+numer[:\s]*([^\r\n]+)/iu', $text, $m)) {
        $data['invoice_number'] = trim($m[1]);
    } elseif (preg_match('/Numer\s+faktury[:\s]*([A-Z0-9\-\_]+)/iu', $text, $m)) {
        $data['invoice_number'] = trim($m[1]);
    }

    if (preg_match('/Data\s+wystawienia:\s*[^\d\r\n]*?(\d{4}-\d{2}-\d{2})/iu', $text, $m)) {
        $data['issue_date'] = $m[1];
    }

    if (preg_match('/Data\s+sprzedaży[:\s]*([0-9]{4}-[0-9]{2}-[0-9]{2})/iu', $text, $m)) {
        $data['sell_date'] = $m[1];
    } elseif (
        preg_match(
            '/Data\s+faktury\/Data\s+dostawy:\s*([0-9]{1,2})\s+([^\s]+)\s+([0-9]{4})/iu',
            $text,
            $m
        )
    ) {
        $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
        $monthNum = polishMonthToNumber($m[2]);
        $year = $m[3];

        if ($monthNum !== null) {
            $date = $year . '-' . $monthNum . '-' . $day;
            $data['issue_date'] = $date;
            $data['sell_date'] = $date;
        }
    }

    if (preg_match_all('/NIP\s+([0-9]{10})/u', $text, $m) && !empty($m[1])) {
        $unique = array_values(array_unique($m[1]));
        $buyer = null;

        foreach ($unique as $nip) {
            if ($nip !== $companyNip) {
                $buyer = $nip;
                break;
            }
        }

        if ($buyer !== null) {
            $data['buyer_nip'] = $buyer;
        }
    } elseif (preg_match('/Numer\s+identyfikatora\s+VAT:\s*PL?([0-9]{10})/iu', $text, $m)) {
        $data['buyer_nip'] = $m[1];
    }

    if (
        preg_match(
            '/Sprzedawca\s+Nabywca.*?NIP\s+[0-9]{10}(.*?)(?:NIP\s+[0-9]{10}|$)/us',
            $text,
            $m
        )
    ) {
        $block = trim($m[1]);
        $lines = preg_split('/\R/u', $block);

        $name = null;

        for ($i = 0, $len = count($lines); $i < $len; $i++) {
            $line = trim($lines[$i]);

            if (
                $line === '' ||
                stripos($line, 'NIP') === 0 ||
                stripos($line, 'Sprzedawca') === 0 ||
                stripos($line, 'Nabywca') === 0 ||
                stripos($line, 'dajstrone.pl') !== false ||
                stripos($line, '@') !== false ||
                stripos($line, 'tel') !== false ||
                stripos($line, 'Rachunki bankowe') !== false ||
                stripos($line, 'PKO Bank') !== false ||
                preg_match('/^PL[0-9]{2}/u', $line)
            ) {
                continue;
            }

            $name = $line;

            if ($i + 1 < $len) {
                $next = trim($lines[$i + 1]);

                if (
                    $next !== '' &&
                    stripos($next, 'NIP') !== 0 &&
                    !preg_match('/\d{2}-\d{3}\s+\S+/u', $next)
                ) {
                    $name .= ' ' . $next;
                }
            }

            break;
        }

        if ($name !== null) {
            $data['buyer_name'] = $name;
        }
    }

    if ($data['seller_name'] === null && preg_match('/Sprzedane\s+przez:\s*(.+)/u', $text, $m)) {
        $data['seller_name'] = trim($m[1]);
    }

    if (
        $data['buyer_name'] === null &&
        preg_match('/Adres\s+do\s+wysyłki\s*(?:\R+)([^\r\n]+)/u', $text, $m)
    ) {
        $data['buyer_name'] = trim($m[1]);
    }

    if (preg_match('/Wartość\s+netto\s+([0-9]+,[0-9]{2})\s+PLN/u', $text, $m)) {
        $data['net_amount'] = str_replace(',', '.', $m[1]);
    }

    if (preg_match('/Wartość\s+VAT\s+([0-9]+,[0-9]{2})\s+PLN/u', $text, $m)) {
        $data['vat_amount'] = str_replace(',', '.', $m[1]);
    }

    if (preg_match('/Wartość\s+brutto\s+([0-9]+,[0-9]{2})\s+PLN/u', $text, $m)) {
        $data['gross_amount'] = str_replace(',', '.', $m[1]);
    } elseif (preg_match('/Wartość\s+faktury\s+([0-9]+,[0-9]{2})\s*zł/u', $text, $m)) {
        $data['gross_amount'] = str_replace(',', '.', $m[1]);
    }

    if (
        $data['gross_amount'] === null &&
        preg_match(
            '/Suma\s+na\s+fakturze(?:\s+VAT)?[:\s]+([0-9]+,[0-9]{2})\s*z[łt]/u',
            $text,
            $m
        )
    ) {
        $data['gross_amount'] = str_replace(',', '.', $m[1]);
    }

    if (preg_match('/W tym\s+[0-9]+,[0-9]{2}\s+([0-9]{1,2})\s+[0-9]+,[0-9]{2}\s+[0-9]+,[0-9]{2}/u', $text, $m)) {
        $data['vat_rate'] = $m[1];
    }

    if (
        $data['net_amount'] === null &&
        $data['vat_amount'] === null &&
        preg_match(
            '/Stawka\s+VAT.*?Suma:\s*([0-9]+,[0-9]{2})\s*z[łt]\s+([0-9]+,[0-9]{2})\s*z[łt]/Us',
            $text,
            $m
        )
    ) {
        $data['net_amount'] = str_replace(',', '.', $m[1]);
        $data['vat_amount'] = str_replace(',', '.', $m[2]);
    }

    if (
        $data['net_amount'] === null &&
        $data['vat_amount'] === null &&
        preg_match_all(
            '/([0-9]{1,2})%\s+([0-9]+,[0-9]{2})\s*z[łt]\s+([0-9]+,[0-9]{2})\s*z[łt]/u',
            $text,
            $matches,
            PREG_SET_ORDER
        )
    ) {
        $last = end($matches);
        $data['vat_rate'] = $last[1];
        $data['net_amount'] = str_replace(',', '.', $last[2]);
        $data['vat_amount'] = str_replace(',', '.', $last[3]);
    }

    if ($data['gross_amount'] !== null) {
        if (preg_match_all('/Suma:\s*([0-9]+,[0-9]{2})\s*zł\s+([0-9]+,[0-9]{2})\s*zł/u', $text, $all, PREG_SET_ORDER)) {
            $gross = (float)$data['gross_amount'];

            foreach ($all as $match) {
                $net = (float)str_replace(',', '.', $match[1]);
                $vat = (float)str_replace(',', '.', $match[2]);

                if (abs(($net + $vat) - $gross) < 0.02) {
                    $data['net_amount'] = $net;
                    $data['vat_amount'] = $vat;
                    break;
                }
            }
        }
    }

    return $data;
}

function parseInvoicesFromCsv(string $filePath, string $companyNip): array
{
    if (!is_readable($filePath)) {
        return [];
    }

    $handle = fopen($filePath, 'r');

    if ($handle === false) {
        return [];
    }

    $header = fgetcsv($handle, 0, ',', '"', '"');

    if ($header === false) {
        fclose($handle);
        return [];
    }

    $map = [];

    foreach ($header as $index => $col) {
        $map[trim($col)] = $index;
    }

    $required = [
        'DATA PŁATNOŚCI ZAMÓWIENIA',
        'CENA PRODUKTÓW (BEZ VAT)',
        'CENA DOTACJI (BEZ VAT)',
        'CENA DOTACJI DO WYSYŁKI (BEZ VAT)',
        'CENA WYSYŁKI (BEZ VAT)',
        'KWOTA VAT OD PRZEDMIOTÓW',
        'KWOTA VAT DOTACJI',
        'KWOTA PODATKU VAT DOTACJI DO WYSYŁKI',
        'KWOTA VAT WYSYŁKI',
        'PODATEK CAŁKOWITY',
        'WALUTA',
        'IDENTYFIKATOR ZAMÓWIENIA',
    ];

    foreach ($required as $colName) {
        if (!array_key_exists($colName, $map)) {
            fclose($handle);
            return [];
        }
    }

    $idxDate = $map['DATA PŁATNOŚCI ZAMÓWIENIA'];
    $idxProdNet = $map['CENA PRODUKTÓW (BEZ VAT)'];
    $idxSubNet = $map['CENA DOTACJI (BEZ VAT)'];
    $idxShipSubNet = $map['CENA DOTACJI DO WYSYŁKI (BEZ VAT)'];
    $idxShipNet = $map['CENA WYSYŁKI (BEZ VAT)'];
    $idxVatProd = $map['KWOTA VAT OD PRZEDMIOTÓW'];
    $idxVatSub = $map['KWOTA VAT DOTACJI'];
    $idxVatShipSub = $map['KWOTA PODATKU VAT DOTACJI DO WYSYŁKI'];
    $idxVatShip = $map['KWOTA VAT WYSYŁKI'];
    $idxTotalTax = $map['PODATEK CAŁKOWITY'];
    $idxCurrency = $map['WALUTA'];
    $idxOrderId = $map['IDENTYFIKATOR ZAMÓWIENIA'];
    $idxInvoiceId = $map['IDENTYFIKATOR FAKTURY'] ?? null;
    $idxInvoiceSubId = $map['IDENTYFIKATOR FAKTURY SUBWENCYJNEJ'] ?? null;

    $toFloat = static function ($value): float {
        if (!is_string($value)) {
            return 0.0;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = str_replace(',', '.', $value);

        if ($value === '') {
            return 0.0;
        }

        return (float)$value;
    };

    $invoicesById = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '"')) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') {
            continue;
        }

        $orderId = trim((string)($row[$idxOrderId] ?? ''));
        $invoiceId = $idxInvoiceId !== null ? trim((string)($row[$idxInvoiceId] ?? '')) : '';
        $invoiceSubId = $idxInvoiceSubId !== null ? trim((string)($row[$idxInvoiceSubId] ?? '')) : '';

        if ($invoiceId !== '') {
            $key = $invoiceId;
        } elseif ($invoiceSubId !== '') {
            $key = $invoiceSubId;
        } elseif ($orderId !== '') {
            $key = $orderId;
        } else {
            continue;
        }

        $dateRaw = (string)($row[$idxDate] ?? '');
        $issueDate = null;

        if (preg_match('/([0-9]{1,2})\s+([^\s]+)\.?\s+([0-9]{4})/u', $dateRaw, $m)) {
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $monthNum = polishMonthToNumber($m[2]);
            $year = $m[3];

            if ($monthNum !== null) {
                $issueDate = $year . '-' . $monthNum . '-' . $day;
            }
        }

        $net = 0.0;
        $net += $toFloat($row[$idxProdNet] ?? '0');
        $net += $toFloat($row[$idxSubNet] ?? '0');
        $net += $toFloat($row[$idxShipSubNet] ?? '0');
        $net += $toFloat($row[$idxShipNet] ?? '0');

        $vat = 0.0;
        $vat += $toFloat($row[$idxVatProd] ?? '0');
        $vat += $toFloat($row[$idxVatSub] ?? '0');
        $vat += $toFloat($row[$idxVatShipSub] ?? '0');
        $vat += $toFloat($row[$idxVatShip] ?? '0');

        $currency = trim((string)($row[$idxCurrency] ?? 'PLN'));

        if (!isset($invoicesById[$key])) {
            $invoicesById[$key] = [
                'invoice_number' => $key,
                'issue_date' => $issueDate,
                'sell_date' => $issueDate,
                'buyer_nip' => 'BRAK',
                'seller_nip' => $companyNip,
                'net_amount' => 0.0,
                'vat_rate' => null,
                'vat_amount' => 0.0,
                'gross_amount' => null,
                'currency' => $currency !== '' ? $currency : 'PLN',
                'buyer_name' => 'Nabywca',
            ];
        }

        $invoicesById[$key]['net_amount'] += $net;
        $invoicesById[$key]['vat_amount'] += $vat;

        if ($issueDate !== null && $invoicesById[$key]['issue_date'] === null) {
            $invoicesById[$key]['issue_date'] = $issueDate;
            $invoicesById[$key]['sell_date'] = $issueDate;
        }
    }

    fclose($handle);

    return array_values($invoicesById);
}

function parseInvoicesFromJpkXml(string $filePath, string $companyNip): array
{
    if (!is_readable($filePath)) {
        return [];
    }

    $dom = new DOMDocument();

    if (!$dom->load($filePath)) {
        return [];
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('jpk', 'http://crd.gov.pl/wzor/2021/12/27/11148/');

    $rows = $xpath->query('//jpk:Ewidencja/jpk:SprzedazWiersz');

    if (!$rows) {
        return [];
    }

    $invoices = [];

    foreach ($rows as $row) {
        $number = trim((string)$xpath->evaluate('string(jpk:DowodSprzedazy)', $row));
        $issueDate = trim((string)$xpath->evaluate('string(jpk:DataWystawienia)', $row));
        $sellDate = trim((string)$xpath->evaluate('string(jpk:DataSprzedazy)', $row));
        $buyerNip = trim((string)$xpath->evaluate('string(jpk:NrKontrahenta)', $row));
        $buyerName = trim((string)$xpath->evaluate('string(jpk:NazwaKontrahenta)', $row));
        $netStr = trim((string)$xpath->evaluate('string(jpk:K_19)', $row));
        $vatStr = trim((string)$xpath->evaluate('string(jpk:K_20)', $row));

        $net = $netStr !== '' ? (float)str_replace(',', '.', $netStr) : 0.0;
        $vat = $vatStr !== '' ? (float)str_replace(',', '.', $vatStr) : 0.0;

        if ($sellDate === '' && $issueDate !== '') {
            $sellDate = $issueDate;
        }

        $invoices[] = [
            'invoice_number' => $number !== '' ? $number : null,
            'issue_date' => $issueDate !== '' ? $issueDate : null,
            'sell_date' => $sellDate !== '' ? $sellDate : null,
            'buyer_nip' => $buyerNip !== '' ? $buyerNip : 'BRAK',
            'seller_nip' => $companyNip,
            'net_amount' => $net,
            'vat_rate' => null,
            'vat_amount' => $vat,
            'gross_amount' => null,
            'currency' => 'PLN',
            'buyer_name' => $buyerName !== '' ? $buyerName : 'Nabywca',
        ];
    }

    return $invoices;
}

function generateJpkFaXml(array $invoices, array $meta): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    if (empty($invoices)) {
        return '';
    }

    $tns = 'http://crd.gov.pl/wzor/2021/12/27/11148/';
    $etd = 'http://crd.gov.pl/xml/schematy/dziedzinowe/mf/2021/06/08/eD/DefinicjeTypy/';
    $xsi = 'http://www.w3.org/2001/XMLSchema-instance';

    $jpk = $dom->createElementNS($tns, 'JPK');
    $jpk->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', $etd);
    $jpk->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $xsi);

    $first = $invoices[0];
    $periodDate = $meta['period_from'] ?? $first['sell_date'] ?? $first['issue_date'];
    $year = substr((string)$periodDate, 0, 4);
    $month = substr((string)$periodDate, 5, 2);

    $naglowek = $dom->createElementNS($tns, 'Naglowek');
    $kodForm = $dom->createElementNS($tns, 'KodFormularza', 'JPK_VAT');
    $kodForm->setAttribute('kodSystemowy', 'JPK_V7M (2)');
    $kodForm->setAttribute('wersjaSchemy', '1-0E');
    $naglowek->appendChild($kodForm);
    $naglowek->appendChild($dom->createElementNS($tns, 'WariantFormularza', '2'));
    $naglowek->appendChild($dom->createElementNS($tns, 'DataWytworzeniaJPK', gmdate('Y-m-d\TH:i:s\Z')));
    $naglowek->appendChild($dom->createElementNS($tns, 'NazwaSystemu', 'PDF2JPK'));

    $cel = $dom->createElementNS($tns, 'CelZlozenia', (string)($meta['purpose'] ?? 1));
    $cel->setAttribute('poz', 'P_7');
    $naglowek->appendChild($cel);

    $naglowek->appendChild($dom->createElementNS($tns, 'KodUrzedu', $meta['office_code'] ?? '2603'));
    $naglowek->appendChild($dom->createElementNS($tns, 'Rok', (string)$year));
    $naglowek->appendChild($dom->createElementNS($tns, 'Miesiac', ltrim((string)$month, '0')));

    $jpk->appendChild($naglowek);

    $podmiot = $dom->createElementNS($tns, 'Podmiot1');
    $podmiot->setAttribute('rola', 'Podatnik');

    $osoba = $dom->createElementNS($tns, 'OsobaFizyczna');
    $osoba->appendChild($dom->createElementNS($etd, 'etd:NIP', $meta['seller_nip'] ?? $first['seller_nip'] ?? ''));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:ImiePierwsze', $meta['first_name'] ?? 'Dominik'));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:Nazwisko', $meta['last_name'] ?? 'Musiał'));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:DataUrodzenia', $meta['birth_date'] ?? '1989-07-13'));
    $osoba->appendChild($dom->createElementNS($tns, 'Email', $meta['email'] ?? 'dominik.musial1989@gmail.com'));

    $podmiot->appendChild($osoba);
    $jpk->appendChild($podmiot);

    $deklaracja = $dom->createElementNS($tns, 'Deklaracja');
    $dekNaglowek = $dom->createElementNS($tns, 'Naglowek');
    $kodFormDekl = $dom->createElementNS($tns, 'KodFormularzaDekl', 'VAT-7');
    $kodFormDekl->setAttribute('kodSystemowy', 'VAT-7 (22)');
    $kodFormDekl->setAttribute('kodPodatku', 'VAT');
    $kodFormDekl->setAttribute('rodzajZobowiazania', 'Z');
    $kodFormDekl->setAttribute('wersjaSchemy', '1-0E');
    $dekNaglowek->appendChild($kodFormDekl);
    $dekNaglowek->appendChild($dom->createElementNS($tns, 'WariantFormularzaDekl', '22'));
    $deklaracja->appendChild($dekNaglowek);

    $totalNet = 0.0;
    $totalVat = 0.0;

    $ewidencja = $dom->createElementNS($tns, 'Ewidencja');

    $lp = 1;
    foreach ($invoices as $invoice) {
        $sprzedaz = $dom->createElementNS($tns, 'SprzedazWiersz');
        $sprzedaz->appendChild($dom->createElementNS($tns, 'LpSprzedazy', (string)$lp));

        $buyerNip = $invoice['buyer_nip'] ?? 'BRAK';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'NrKontrahenta', $buyerNip));

        $buyerName = $invoice['buyer_name'] ?? ($meta['buyer_name'] ?? 'Nabywca');
        $sprzedaz->appendChild($dom->createElementNS($tns, 'NazwaKontrahenta', $buyerName));

        $number = $invoice['invoice_number'] ?? '';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'DowodSprzedazy', $number));

        $issueDate = $invoice['issue_date'] ?? '';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'DataWystawienia', $issueDate));

        $sprzedaz->appendChild($dom->createElementNS($tns, 'GTU_12', '1'));

        $net = (float)($invoice['net_amount'] ?? 0);
        $vat = (float)($invoice['vat_amount'] ?? 0);

        $sprzedaz->appendChild($dom->createElementNS($tns, 'K_19', number_format($net, 2, '.', '')));
        $sprzedaz->appendChild($dom->createElementNS($tns, 'K_20', number_format($vat, 2, '.', '')));

        $ewidencja->appendChild($sprzedaz);

        $totalNet += $net;
        $totalVat += $vat;

        $lp++;
    }

    $pozycje = $dom->createElementNS($tns, 'PozycjeSzczegolowe');
    $pozycje->appendChild($dom->createElementNS($tns, 'P_19', number_format($totalNet, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'P_20', number_format($totalVat, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'P_37', number_format($totalNet, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'P_38', number_format($totalVat, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'P_51', number_format($totalVat, 2, '.', '')));
    $deklaracja->appendChild($pozycje);
    $deklaracja->appendChild($dom->createElementNS($tns, 'Pouczenia', '1'));

    $jpk->appendChild($deklaracja);

    $sprzedazCtrl = $dom->createElementNS($tns, 'SprzedazCtrl');
    $sprzedazCtrl->appendChild($dom->createElementNS($tns, 'LiczbaWierszySprzedazy', (string)count($invoices)));
    $sprzedazCtrl->appendChild($dom->createElementNS($tns, 'PodatekNalezny', number_format($totalVat, 2, '.', '')));
    $ewidencja->appendChild($sprzedazCtrl);

    $zakupCtrl = $dom->createElementNS($tns, 'ZakupCtrl');
    $zakupCtrl->appendChild($dom->createElementNS($tns, 'LiczbaWierszyZakupow', '0'));
    $zakupCtrl->appendChild($dom->createElementNS($tns, 'PodatekNaliczony', '0.00'));
    $ewidencja->appendChild($zakupCtrl);

    $jpk->appendChild($ewidencja);

    $dom->appendChild($jpk);

    return $dom->saveXML();
}

$xml = null;
$error = null;
$debugText = null;
$companyName = $_POST['company_name'] ?? 'Exclusive Kicks Krystian Gędłek';
$companyNip = $_POST['company_nip'] ?? '6322033623';
$officeCode = $_POST['office_code'] ?? '2415';
$firstName = $_POST['first_name'] ?? 'Krystian';
$lastName = $_POST['last_name'] ?? 'Gędłek';
$birthDate = $_POST['birth_date'] ?? '2004-09-13';
$email = $_POST['email'] ?? 'exclusive_kicks@wp.pl';
$phone = $_POST['phone'] ?? '512736370';
$action = $_POST['action'] ?? 'preview';

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && isset($_FILES['invoice_pdf'])) {
    $allInvoices = [];
    $rawText = '';

    $tmpNames = $_FILES['invoice_pdf']['tmp_name'] ?? [];
    $origNames = $_FILES['invoice_pdf']['name'] ?? [];

    if (!is_array($tmpNames)) {
        $tmpNames = [$tmpNames];
        $origNames = is_array($origNames) ? $origNames : [$origNames];
    }

    $hasUpload = false;

    foreach ($tmpNames as $tmp) {
        if (is_string($tmp) && $tmp !== '' && is_uploaded_file($tmp)) {
            $hasUpload = true;
            break;
        }
    }

    if (!$hasUpload && $action === 'download' && isset($_SESSION['last_jpk_xml'], $_SESSION['last_jpk_meta'])) {
        $xml = (string)$_SESSION['last_jpk_xml'];
        $meta = (array)$_SESSION['last_jpk_meta'];
        $baseDate = $meta['period_from'] ?? date('Y-m-d');
        $safeDate = preg_replace('/[^0-9\-]/', '', (string)$baseDate);

        if ($safeDate === '') {
            $safeDate = date('Y-m-d');
        }

        $fileName = 'jpk-' . $safeDate . '.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($xml));

        echo $xml;
        exit;
    }

    if ($hasUpload) {
        if (!is_array($origNames)) {
            $origNames = [$origNames];
        }

        $count = count($tmpNames);

        for ($i = 0; $i < $count; $i++) {
            $tmpPath = $tmpNames[$i];
            $origName = (string)($origNames[$i] ?? '');

            if (!is_uploaded_file($tmpPath)) {
                continue;
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $invoices = [];

            if ($ext === 'pdf') {
                $invoices = parseInvoicesFromPdf($tmpPath, $companyNip);
                $rawText = $GLOBALS['last_pdf_text'] ?? $rawText;
            } elseif ($ext === 'csv') {
                $invoices = parseInvoicesFromCsv($tmpPath, $companyNip);
            } elseif ($ext === 'xml') {
                $invoices = parseInvoicesFromJpkXml($tmpPath, $companyNip);
            }

            if (!empty($invoices)) {
                $allInvoices = array_merge($allInvoices, $invoices);
            }
        }

        if (empty($allInvoices)) {
            if ($rawText !== '') {
                $error = 'Nie udało się dopasować danych faktur do bieżących wzorców. Poniżej jest surowy tekst z PDF do analizy.';
                $debugText = $rawText;
            } else {
                $error = 'Nie udało się odczytać danych faktur z żadnego pliku. Obsługiwane są PDF, CSV (raport VAT) oraz XML (JPK).';
            }
        } else {
            $first = $allInvoices[0];

            $meta = [
                'purpose' => 1,
                'period_from' => $first['sell_date'] ?? $first['issue_date'],
                'period_to' => $first['sell_date'] ?? $first['issue_date'],
                'office_code' => $officeCode,
                'seller_nip' => $companyNip,
                'seller_name' => $companyName,
                'buyer_name' => 'Nabywca',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'email' => $email,
                'phone' => $phone,
            ];

            $invoiceData = $allInvoices;
            $xml = generateJpkFaXml($allInvoices, $meta);

            $_SESSION['last_jpk_xml'] = $xml;
            $_SESSION['last_jpk_meta'] = $meta;

            if ($action === 'download' && $error === null && $xml !== null) {
                $baseDate = $meta['period_from'] ?? date('Y-m-d');
                $safeDate = preg_replace('/[^0-9\-]/', '', (string)$baseDate);

                if ($safeDate === '') {
                    $safeDate = date('Y-m-d');
                }

                $fileName = 'jpk-' . $safeDate . '.xml';

                header('Content-Type: application/xml; charset=UTF-8');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                header('Content-Length: ' . strlen($xml));

                echo $xml;
                exit;
            }
        }
    } elseif ($action === 'preview') {
        $error = 'Najpierw wgraj pliki i wygeneruj JPK.';
    } elseif ($action === 'download' && !isset($_SESSION['last_jpk_xml'])) {
        $error = 'Brak wcześniej wygenerowanego JPK. Najpierw wgraj pliki i użyj podglądu lub zapisu.';
    }
}

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <title>PDF → JPK XML</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background: #f3f4f6;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 16px 48px;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 8px;
        }
        p {
            margin-top: 0;
            color: #4b5563;
        }
        form {
            margin-top: 16px;
            padding: 16px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .field {
            margin-bottom: 12px;
        }
        label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        input[type="file"],
        input[type="text"] {
            width: 100%;
            font-size: 14px;
            padding: 6px 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
        }
        button {
            border: none;
            border-radius: 4px;
            background: #2563eb;
            color: #ffffff;
            padding: 8px 16px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover {
            background: #1d4ed8;
        }
        .error {
            margin-top: 12px;
            color: #b91c1c;
            font-size: 14px;
        }
        .result {
            margin-top: 20px;
            padding: 16px;
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        textarea {
            width: 100%;
            box-sizing: border-box;
            min-height: 320px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 12px;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            resize: vertical;
        }
        .hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 8px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Konwerter sprzedaży → JPK XML</h1>
    <p>Wgraj faktury PDF, raport VAT w CSV lub plik JPK XML, a aplikacja spróbuje wygenerować plik JPK w formacie zbliżonym do JPK_V7M.</p>

    <form method="post" enctype="multipart/form-data">
        <div class="field">
            <label for="company_name">Nazwa firmy (sprzedawca)</label>
            <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="company_nip">NIP firmy</label>
            <input type="text" id="company_nip" name="company_nip" value="<?php echo htmlspecialchars($companyNip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="office_code">Kod urzędu skarbowego</label>
            <input type="text" id="office_code" name="office_code" value="<?php echo htmlspecialchars($officeCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="first_name">Imię podatnika</label>
            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="last_name">Nazwisko podatnika</label>
            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="birth_date">Data urodzenia</label>
            <input type="date" id="birth_date" name="birth_date" value="<?php echo htmlspecialchars($birthDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="email">Email podatnika</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="phone">Telefon podatnika</label>
            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="field">
            <label for="invoice_pdf">Pliki źródłowe (PDF / CSV / XML)</label>
            <input type="file" id="invoice_pdf" name="invoice_pdf[]" accept="application/pdf,text/csv,.csv,application/xml,text/xml,.xml" multiple>
        </div>
        <button type="submit" name="action" value="preview">Podgląd JPK XML</button>
        <button type="submit" name="action" value="download">Zapisz JPK jako plik XML</button>
        <div class="hint">
            Do dokładnego działania trzeba dopasować wyrażenia regularne do formatów Twoich faktur i dopracować strukturę JPK zgodnie z oficjalnym XSD (np. JPK_FA lub JPK_V7).
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
    </form>

    <?php if ($debugText !== null): ?>
        <div class="result">
            <h2>Surowy tekst z PDF (debug)</h2>
            <textarea readonly><?php echo htmlspecialchars($debugText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
        </div>
    <?php endif; ?>

    <?php if (isset($invoiceData) && !$error): ?>
        <div class="result">
            <h2>Odczytane dane z faktury</h2>
            <textarea readonly><?php echo htmlspecialchars(var_export($invoiceData, true), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
            <div class="hint">
                Sprawdź, czy wartości zgadzają się z fakturami. Jeśli coś jest błędne albo puste, trzeba dostosować wyrażenia regularne w funkcji <strong>extractInvoiceDataFromText</strong>.
            </div>
        </div>
    <?php endif; ?>

    <?php if ($xml): ?>
        <div class="result">
            <h2>Wygenerowany JPK XML</h2>
            <textarea readonly><?php echo htmlspecialchars($xml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
            <div class="hint">
                Skopiuj treść do pliku z rozszerzeniem <strong>.xml</strong> i zweryfikuj go w narzędziu MF lub swoim systemie księgowym.
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
