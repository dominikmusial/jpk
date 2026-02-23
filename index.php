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

function jpkJobsDir(): string
{
    return __DIR__ . '/jobs';
}

function jpkBasketDir(): string
{
    return __DIR__ . '/basket';
}

function loadBasketFiles(): array
{
    $dir = jpkBasketDir();

    if (!is_dir($dir)) {
        return [];
    }

    $files = [];

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $dir . '/' . $name;

        if (!is_file($path)) {
            continue;
        }

        $files[] = $name;
    }

    sort($files);

    return $files;
}

function clearBasket(): void
{
    $dir = jpkBasketDir();

    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $dir . '/' . $name;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function removeBasketFile(string $fileName): void
{
    $dir = jpkBasketDir();
    $base = basename($fileName);
    $path = $dir . '/' . $base;

    if (is_file($path)) {
        @unlink($path);
    }
}

function deleteDirectoryRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $path = $dir . '/' . $name;

        if (is_dir($path)) {
            deleteDirectoryRecursive($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function loadJpkJobs(): array
{
    $jobsDir = jpkJobsDir();

    if (!is_dir($jobsDir)) {
        return [];
    }

    $jobs = [];

    foreach (glob($jobsDir . '/*.json') ?: [] as $metaPath) {
        $data = json_decode((string)file_get_contents($metaPath), true);

        if (!is_array($data) || !isset($data['id'])) {
            continue;
        }

        $jobs[] = $data;
    }

    usort($jobs, function (array $a, array $b): int {
        $aTime = $a['created_at'] ?? '';
        $bTime = $b['created_at'] ?? '';

        return strcmp($bTime, $aTime);
    });

    return $jobs;
}

function jpkRunWorker(): void
{
    $jobsDir = jpkJobsDir();

    if (!is_dir($jobsDir)) {
        return;
    }

    $metaFiles = glob($jobsDir . '/*.json') ?: [];
    sort($metaFiles);

    foreach ($metaFiles as $metaPath) {
        $raw = file_get_contents($metaPath);
        $job = json_decode($raw !== false ? $raw : '', true);

        if (!is_array($job) || ($job['status'] ?? '') !== 'pending') {
            continue;
        }

        $jobId = (string)($job['id'] ?? pathinfo($metaPath, PATHINFO_FILENAME));
        $jobDir = $jobsDir . '/' . $jobId;

        if (!is_dir($jobDir)) {
            $job['status'] = 'error';
            $job['error'] = 'Brak katalogu z plikami dla zadania.';
            file_put_contents($metaPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            continue;
        }

        $allInvoices = [];
        $companyNip = (string)($job['meta']['company_nip'] ?? '');

        foreach ($job['files'] as $fileInfo) {
            $storedName = (string)($fileInfo['stored_name'] ?? '');
            $originalName = (string)($fileInfo['original_name'] ?? '');

            if ($storedName === '') {
                continue;
            }

            $path = $jobDir . '/' . $storedName;

            if (!is_readable($path)) {
                continue;
            }

            $extSource = $originalName !== '' ? $originalName : $path;
            $ext = strtolower(pathinfo($extSource, PATHINFO_EXTENSION));
            $invoices = [];

            if ($ext === 'pdf') {
                $invoices = parseInvoicesFromPdf($path, $companyNip);
            } elseif ($ext === 'csv') {
                $invoices = parseInvoicesFromCsv($path, $companyNip);
            } elseif ($ext === 'xml') {
                $invoices = parseInvoicesFromJpkXml($path, $companyNip);
            }

            if (!empty($invoices)) {
                $allInvoices = array_merge($allInvoices, $invoices);
            }
        }

        if (empty($allInvoices)) {
            $job['status'] = 'error';
            $job['error'] = 'Nie udało się odczytać danych faktur dla zadania.';
            file_put_contents($metaPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            continue;
        }

        $first = $allInvoices[0];

        $meta = [
            'purpose' => 1,
            'period_from' => $first['sell_date'] ?? $first['issue_date'],
            'period_to' => $first['sell_date'] ?? $first['issue_date'],
            'office_code' => $job['meta']['office_code'] ?? '2603',
            'seller_nip' => $companyNip,
            'seller_name' => $job['meta']['company_name'] ?? '',
            'buyer_name' => 'Nabywca',
            'first_name' => $job['meta']['first_name'] ?? '',
            'last_name' => $job['meta']['last_name'] ?? '',
            'birth_date' => $job['meta']['birth_date'] ?? '',
            'email' => $job['meta']['email'] ?? '',
            'phone' => $job['meta']['phone'] ?? '',
        ];

        $xml = generateJpkFaXml($allInvoices, $meta);
        $resultPath = $jobsDir . '/' . $jobId . '.xml';
        file_put_contents($resultPath, $xml);

        $job['status'] = 'done';
        $job['result_file'] = 'jobs/' . $jobId . '.xml';
        $job['error'] = null;
        $job['updated_at'] = date('c');

        file_put_contents($metaPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (defined('STDOUT')) {
            fwrite(STDOUT, 'Przetworzono zadanie ' . $jobId . PHP_EOL);
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'worker') {
    jpkRunWorker();
    return;
}

set_time_limit(0);
ini_set('memory_limit', '1024M');
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

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && $action === 'run_worker') {
    $executedInBackground = false;

    if (function_exists('exec')) {
        $php = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg(__FILE__);
        $cmd = $php . ' ' . $script . ' worker > /dev/null 2>&1 &';
        @exec($cmd, $out, $code);

        if (!isset($code) || $code === 0) {
            $executedInBackground = true;
        }
    }

    if (!$executedInBackground) {
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }

        jpkRunWorker();
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST') {
    if ($action === 'basket_add' && isset($_FILES['invoice_pdf'])) {
        $tmpNames = $_FILES['invoice_pdf']['tmp_name'] ?? [];
        $origNames = $_FILES['invoice_pdf']['name'] ?? [];

        if (!is_array($tmpNames)) {
            $tmpNames = [$tmpNames];
            $origNames = is_array($origNames) ? $origNames : [$origNames];
        }

        $dir = jpkBasketDir();

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $count = count($tmpNames);

        for ($i = 0; $i < $count; $i++) {
            $tmpPath = $tmpNames[$i];
            $origName = (string)($origNames[$i] ?? '');

            if (!is_uploaded_file($tmpPath) || $origName === '') {
                continue;
            }

            $clean = preg_replace('/[^A-Za-z0-9\.\-\_]/', '_', $origName);

            if ($clean === '') {
                $clean = 'plik';
            }

            $targetPath = $dir . '/' . $clean;
            $n = 1;

            while (file_exists($targetPath)) {
                $targetPath = $dir . '/' . $n . '_' . $clean;
                $n++;
            }

            @move_uploaded_file($tmpPath, $targetPath);
        }
    } elseif ($action === 'basket_remove' && isset($_POST['basket_file'])) {
        removeBasketFile((string)$_POST['basket_file']);
    } elseif ($action === 'basket_clear') {
        clearBasket();
    } elseif ($action === 'basket_create_job') {
        $basketDir = jpkBasketDir();
        $files = [];

        if (is_dir($basketDir)) {
            foreach (scandir($basketDir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $path = $basketDir . '/' . $name;

                if (is_file($path)) {
                    $files[] = $name;
                }
            }
        }

        if (empty($files)) {
            $error = 'Brak plików w koszyku. Najpierw dodaj pliki.';
        } else {
            $jobsDir = jpkJobsDir();

            if (!is_dir($jobsDir)) {
                mkdir($jobsDir, 0777, true);
            }

            $jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
            $jobDir = $jobsDir . '/' . $jobId;

            if (!is_dir($jobDir)) {
                mkdir($jobDir, 0777, true);
            }

            $storedFiles = [];
            $index = 1;

            foreach ($files as $fileName) {
                $sourcePath = $basketDir . '/' . $fileName;

                if (!is_file($sourcePath)) {
                    continue;
                }

                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                if ($ext === '') {
                    $ext = 'dat';
                }

                $storedName = 'file-' . $index . '.' . $ext;
                $targetPath = $jobDir . '/' . $storedName;

                if (!@rename($sourcePath, $targetPath)) {
                    if (!@copy($sourcePath, $targetPath)) {
                        continue;
                    }
                }

                $storedFiles[] = [
                    'original_name' => $fileName,
                    'stored_name' => $storedName,
                    'extension' => $ext,
                ];

                $index++;
            }

            clearBasket();

            if (empty($storedFiles)) {
                $error = 'Nie udało się przenieść plików z koszyka do kolejki.';
            } else {
                $job = [
                    'id' => $jobId,
                    'created_at' => date('c'),
                    'status' => 'pending',
                    'meta' => [
                        'company_name' => $companyName,
                        'company_nip' => $companyNip,
                        'office_code' => $officeCode,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'birth_date' => $birthDate,
                        'email' => $email,
                        'phone' => $phone,
                    ],
                    'files' => $storedFiles,
                    'result_file' => null,
                    'error' => null,
                ];

                $metaPath = $jobsDir . '/' . $jobId . '.json';
                file_put_contents($metaPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $jobCreatedId = $jobId;
            }
        }
    } elseif ($action === 'job_delete' && isset($_POST['job_id'])) {
        $jobsDir = jpkJobsDir();
        $jobId = basename((string)$_POST['job_id']);
        $metaPath = $jobsDir . '/' . $jobId . '.json';
        $resultPath = $jobsDir . '/' . $jobId . '.xml';
        $jobDir = $jobsDir . '/' . $jobId;

        if (is_file($metaPath)) {
            @unlink($metaPath);
        }

        if (is_file($resultPath)) {
            @unlink($resultPath);
        }

        if (is_dir($jobDir)) {
            deleteDirectoryRecursive($jobDir);
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && isset($_FILES['invoice_pdf']) && $action !== 'basket_add') {
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

        $jobsDir = jpkJobsDir();

        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0777, true);
        }

        $jobId = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $jobDir = $jobsDir . '/' . $jobId;

        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0777, true);
        }

        $storedFiles = [];
        $count = count($tmpNames);

        for ($i = 0; $i < $count; $i++) {
            $tmpPath = $tmpNames[$i];
            $origName = (string)($origNames[$i] ?? '');

            if (!is_uploaded_file($tmpPath)) {
                continue;
            }

            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext === '') {
                $ext = 'dat';
            }

            $storedName = 'file-' . ($i + 1) . '.' . $ext;
            $targetPath = $jobDir . '/' . $storedName;

            if (!@move_uploaded_file($tmpPath, $targetPath)) {
                continue;
            }

            $storedFiles[] = [
                'original_name' => $origName,
                'stored_name' => $storedName,
                'extension' => $ext,
            ];
        }

        if (empty($storedFiles)) {
            $error = 'Nie udało się zapisać żadnego pliku do kolejki zadań.';
        } else {
            $job = [
                'id' => $jobId,
                'created_at' => date('c'),
                'status' => 'pending',
                'meta' => [
                    'company_name' => $companyName,
                    'company_nip' => $companyNip,
                    'office_code' => $officeCode,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'birth_date' => $birthDate,
                    'email' => $email,
                    'phone' => $phone,
                ],
                'files' => $storedFiles,
                'result_file' => null,
                'error' => null,
            ];

            $metaPath = $jobsDir . '/' . $jobId . '.json';
            file_put_contents($metaPath, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $jobCreatedId = $jobId;
        }
    } elseif ($action === 'preview') {
        $error = 'Najpierw wgraj pliki i wygeneruj JPK.';
    } elseif ($action === 'download' && !isset($_SESSION['last_jpk_xml'])) {
        $error = 'Brak wcześniej wygenerowanego JPK. Najpierw wgraj pliki i użyj podglądu lub zapisu.';
    }
}

$jobs = loadJpkJobs();
$basketFiles = loadBasketFiles();

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
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border-bottom: 1px solid #e5e7eb;
            padding: 6px 8px;
            text-align: left;
        }
        th {
            background: #f9fafb;
            font-weight: 600;
        }
        .job-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .job-actions form {
            margin: 0;
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
        <div id="file_list" class="field"></div>
        <button type="submit" name="action" value="basket_add">Wyślij pliki na serwer</button>
        <button type="submit" name="action" value="basket_create_job">Utwórz zadanie JPK z plików na serwerze</button>
        <div class="hint">
            Najpierw wyślij pliki na serwer (możesz robić to w kilku paczkach), a następnie utwórz jedno zadanie JPK z wszystkich plików zapisanych na serwerze.
        </div>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
        <?php endif; ?>
    </form>

    <?php if (isset($jobCreatedId) && !$error): ?>
        <div class="result">
            <h2>Przetwarzanie w tle</h2>
            <p>Zadanie <?php echo htmlspecialchars($jobCreatedId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> zostało dodane do kolejki. Po zakończeniu pojawi się na liście poniżej.</p>
        </div>
    <?php endif; ?>

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

    <?php if (!empty($basketFiles)): ?>
        <div class="result">
            <h2>Pliki zapisane na serwerze</h2>
            <p>Aktualnie na serwerze: <?php echo htmlspecialchars((string)count($basketFiles), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> plików.</p>
            <ul>
                <?php foreach ($basketFiles as $basketFile): ?>
                    <li>
                        <?php echo htmlspecialchars($basketFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="basket_file" value="<?php echo htmlspecialchars($basketFile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <button type="submit" name="action" value="basket_remove">Usuń z serwera</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" style="margin-top: 8px;">
                <button type="submit" name="action" value="basket_clear">Usuń wszystkie pliki z serwera</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (!empty($jobs)): ?>
        <div class="result">
            <h2>Kolejka zadań JPK</h2>
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Data utworzenia</th>
                    <th>Status</th>
                    <th>Liczba plików</th>
                    <th>Akcja</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($job['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($job['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($job['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)count($job['files'] ?? []), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                        <td>
                            <div class="job-actions">
                                <?php $jobStatus = (string)($job['status'] ?? ''); ?>
                                <?php if (($job['status'] ?? '') === 'done' && !empty($job['result_file'])): ?>
                                    <a href="<?php echo htmlspecialchars((string)$job['result_file'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Pobierz</a>
                                <?php elseif (($job['status'] ?? '') === 'error' && !empty($job['error'])): ?>
                                    <span><?php echo htmlspecialchars((string)$job['error'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                <?php else: ?>
                                    <span>Oczekuje</span>
                                <?php endif; ?>
                                <form method="post">
                                    <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)($job['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <?php $btnLabel = $jobStatus === 'pending' ? 'Anuluj' : 'Usuń'; ?>
                                    <button type="submit" name="action" value="job_delete"><?php echo htmlspecialchars($btnLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top: 12px;">
                <button type="submit" name="action" value="run_worker">Przelicz wszystkie oczekujące zadania teraz</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<script>
    var fileInput = document.getElementById('invoice_pdf');
    var fileList = document.getElementById('file_list');
    function renderFileList() {
        if (!fileInput || !fileList) {
            return;
        }
        var files = fileInput.files;
        if (!files || files.length === 0) {
            fileList.innerHTML = '';
            return;
        }
        var html = '<label>Wybrane pliki</label><ul style="list-style:none;padding-left:0;margin-top:4px;">';
        for (var i = 0; i < files.length; i++) {
            html += '<li style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">' +
                '<span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:70%;">' +
                files[i].name +
                '</span>' +
                '<button type="button" data-remove-index="' + i + '" style="margin-left:8px;border:none;border-radius:4px;background:#ef4444;color:#ffffff;padding:2px 8px;font-size:11px;cursor:pointer;">Usuń</button>' +
                '</li>';
        }
        html += '</ul>';
        fileList.innerHTML = html;
        var buttons = fileList.querySelectorAll('button[data-remove-index]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var index = parseInt(this.getAttribute('data-remove-index'), 10);
                var dt = new DataTransfer();
                for (var i = 0; i < fileInput.files.length; i++) {
                    if (i === index) {
                        continue;
                    }
                    dt.items.add(fileInput.files[i]);
                }
                fileInput.files = dt.files;
                renderFileList();
            });
        });
    }
    if (fileInput && fileList) {
        fileInput.addEventListener('change', renderFileList);
    }

    var basketAddButton = document.querySelector('button[name="action"][value="basket_add"]');
    var mainForm = document.querySelector('form[enctype="multipart/form-data"]');
    var isUploading = false;

    if (basketAddButton && mainForm && fileInput) {
        basketAddButton.addEventListener('click', function (e) {
            if (!fileInput.files || fileInput.files.length === 0 || isUploading) {
                return;
            }
            e.preventDefault();
            isUploading = true;
            basketAddButton.disabled = true;

            var statusEl = document.getElementById('upload_status');
            if (!statusEl) {
                statusEl = document.createElement('div');
                statusEl.id = 'upload_status';
                statusEl.className = 'hint';
                mainForm.appendChild(statusEl);
            }

            var files = Array.prototype.slice.call(fileInput.files);
            var total = files.length;
            var index = 0;

            function uploadNext() {
                if (index >= total) {
                    statusEl.textContent = 'Dodano do koszyka ' + total + ' plików. Odświeżam widok...';
                    window.location.reload();
                    return;
                }

                var file = files[index];
                statusEl.textContent = 'Wysyłanie pliku ' + (index + 1) + ' z ' + total + '...';

                var fd = new FormData();
                fd.append('action', 'basket_add');
                fd.append('invoice_pdf[]', file, file.name);

                fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                }).then(function () {
                    index++;
                    uploadNext();
                }).catch(function () {
                    index++;
                    uploadNext();
                });
            }

            uploadNext();
        });
    }
</script>
</body>
</html>
