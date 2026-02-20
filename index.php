<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

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
    ];

    if (preg_match('/Faktura\s+numer[:\s]*([^\r\n]+)/iu', $text, $m)) {
        $data['invoice_number'] = trim($m[1]);
    } elseif (preg_match('/Numer\s+faktury[:\s]*([A-Z0-9\-\_]+)/iu', $text, $m)) {
        $data['invoice_number'] = trim($m[1]);
    }

    if (preg_match('/Data\s+wystawienia:\s*[^\d\r\n]*?(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
        $data['issue_date'] = $m[1];
    }

    if (preg_match('/Data\s+sprzedaży[:\s]*([0-9]{4}-[0-9]{2}-[0-9]{2})/u', $text, $m)) {
        $data['sell_date'] = $m[1];
    } elseif (
        preg_match(
            '/Data\s+faktury\/Data\s+dostawy:\s*([0-9]{1,2})\s+([^\s]+)\s+([0-9]{4})/u',
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
    } elseif (preg_match('/Sprzedane\s+przez:\s*(.+)/u', $text, $m)) {
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

    if (preg_match('/W tym\s+[0-9]+,[0-9]{2}\s+([0-9]{1,2})\s+[0-9]+,[0-9]{2}\s+[0-9]+,[0-9]{2}/u', $text, $m)) {
        $data['vat_rate'] = $m[1];
    }

    if (
        $data['net_amount'] === null &&
        $data['vat_amount'] === null &&
        preg_match(
            '/Stawka\s+VAT.*?Suma:\s*([0-9]+,[0-9]{2})\s*zł\s+([0-9]+,[0-9]{2})\s*zł/Us',
            $text,
            $m
        )
    ) {
        $data['net_amount'] = str_replace(',', '.', $m[1]);
        $data['vat_amount'] = str_replace(',', '.', $m[2]);
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

    $jpk = $dom->createElementNS($tns, 'tns:JPK');
    $jpk->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:tns', $tns);
    $jpk->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:etd', $etd);
    $jpk->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $xsi);

    $first = $invoices[0];
    $periodDate = $meta['period_from'] ?? $first['sell_date'] ?? $first['issue_date'];
    $year = substr((string)$periodDate, 0, 4);
    $month = substr((string)$periodDate, 5, 2);

    $naglowek = $dom->createElementNS($tns, 'tns:Naglowek');
    $kodForm = $dom->createElementNS($tns, 'tns:KodFormularza', 'JPK_VAT');
    $kodForm->setAttribute('kodSystemowy', 'JPK_V7M (2)');
    $kodForm->setAttribute('wersjaSchemy', '1-0E');
    $naglowek->appendChild($kodForm);
    $naglowek->appendChild($dom->createElementNS($tns, 'tns:WariantFormularza', '2'));
    $naglowek->appendChild($dom->createElementNS($tns, 'tns:DataWytworzeniaJPK', gmdate('Y-m-d\TH:i:s\Z')));
    $naglowek->appendChild($dom->createElementNS($tns, 'tns:NazwaSystemu', 'PDF2JPK'));

    $cel = $dom->createElementNS($tns, 'tns:CelZlozenia', (string)($meta['purpose'] ?? 1));
    $cel->setAttribute('poz', 'P_7');
    $naglowek->appendChild($cel);

    $naglowek->appendChild($dom->createElementNS($tns, 'tns:KodUrzedu', $meta['office_code'] ?? '2603'));
    $naglowek->appendChild($dom->createElementNS($tns, 'tns:Rok', (string)$year));
    $naglowek->appendChild($dom->createElementNS($tns, 'tns:Miesiac', ltrim((string)$month, '0')));

    $jpk->appendChild($naglowek);

    $podmiot = $dom->createElementNS($tns, 'tns:Podmiot1');
    $podmiot->setAttribute('rola', 'Podatnik');

    $osoba = $dom->createElementNS($tns, 'tns:OsobaFizyczna');
    $osoba->appendChild($dom->createElementNS($etd, 'etd:NIP', $meta['seller_nip'] ?? $first['seller_nip'] ?? ''));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:ImiePierwsze', $meta['first_name'] ?? 'Dominik'));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:Nazwisko', $meta['last_name'] ?? 'Musiał'));
    $osoba->appendChild($dom->createElementNS($etd, 'etd:DataUrodzenia', $meta['birth_date'] ?? '1989-07-13'));
    $osoba->appendChild($dom->createElementNS($tns, 'tns:Email', $meta['email'] ?? 'dominik.musial1989@gmail.com'));
    $osoba->appendChild($dom->createElementNS($tns, 'tns:Telefon', $meta['phone'] ?? '512736370'));

    $podmiot->appendChild($osoba);
    $jpk->appendChild($podmiot);

    $deklaracja = $dom->createElementNS($tns, 'tns:Deklaracja');
    $dekNaglowek = $dom->createElementNS($tns, 'tns:Naglowek');
    $kodFormDekl = $dom->createElementNS($tns, 'tns:KodFormularzaDekl', 'VAT-7');
    $kodFormDekl->setAttribute('kodSystemowy', 'VAT-7 (22)');
    $kodFormDekl->setAttribute('kodPodatku', 'VAT');
    $kodFormDekl->setAttribute('rodzajZobowiazania', 'Z');
    $kodFormDekl->setAttribute('wersjaSchemy', '1-0E');
    $dekNaglowek->appendChild($kodFormDekl);
    $dekNaglowek->appendChild($dom->createElementNS($tns, 'tns:WariantFormularzaDekl', '22'));
    $deklaracja->appendChild($dekNaglowek);

    $totalNet = 0.0;
    $totalVat = 0.0;

    $ewidencja = $dom->createElementNS($tns, 'tns:Ewidencja');

    $lp = 1;
    foreach ($invoices as $invoice) {
        $sprzedaz = $dom->createElementNS($tns, 'tns:SprzedazWiersz');
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:LpSprzedazy', (string)$lp));

        $buyerNip = $invoice['buyer_nip'] ?? 'BRAK';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:NrKontrahenta', $buyerNip));

        $buyerName = $invoice['buyer_name'] ?? ($meta['buyer_name'] ?? 'Nabywca');
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:NazwaKontrahenta', $buyerName));

        $number = $invoice['invoice_number'] ?? '';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:DowodSprzedazy', $number));

        $issueDate = $invoice['issue_date'] ?? '';
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:DataWystawienia', $issueDate));

        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:GTU_12', '1'));

        $net = (float)($invoice['net_amount'] ?? 0);
        $vat = (float)($invoice['vat_amount'] ?? 0);

        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:K_19', number_format($net, 2, '.', '')));
        $sprzedaz->appendChild($dom->createElementNS($tns, 'tns:K_20', number_format($vat, 2, '.', '')));

        $ewidencja->appendChild($sprzedaz);

        $totalNet += $net;
        $totalVat += $vat;

        $lp++;
    }

    $pozycje = $dom->createElementNS($tns, 'tns:PozycjeSzczegolowe');
    $pozycje->appendChild($dom->createElementNS($tns, 'tns:P_19', number_format($totalNet, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'tns:P_20', number_format($totalVat, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'tns:P_37', number_format($totalNet, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'tns:P_38', number_format($totalVat, 2, '.', '')));
    $pozycje->appendChild($dom->createElementNS($tns, 'tns:P_51', number_format($totalVat, 2, '.', '')));
    $deklaracja->appendChild($pozycje);
    $deklaracja->appendChild($dom->createElementNS($tns, 'tns:Pouczenia', '1'));

    $jpk->appendChild($deklaracja);

    $sprzedazCtrl = $dom->createElementNS($tns, 'tns:SprzedazCtrl');
    $sprzedazCtrl->appendChild($dom->createElementNS($tns, 'tns:LiczbaWierszySprzedazy', (string)count($invoices)));
    $sprzedazCtrl->appendChild($dom->createElementNS($tns, 'tns:PodatekNalezny', number_format($totalVat, 2, '.', '')));
    $ewidencja->appendChild($sprzedazCtrl);

    $zakupCtrl = $dom->createElementNS($tns, 'tns:ZakupCtrl');
    $zakupCtrl->appendChild($dom->createElementNS($tns, 'tns:LiczbaWierszyZakupow', '0'));
    $zakupCtrl->appendChild($dom->createElementNS($tns, 'tns:PodatekNaliczony', '0.00'));
    $ewidencja->appendChild($zakupCtrl);

    $jpk->appendChild($ewidencja);

    $dom->appendChild($jpk);

    return $dom->saveXML();
}

$xml = null;
$error = null;
$debugText = null;
$companyName = $_POST['company_name'] ?? 'dajstrone.pl Dominik Musiał';
$companyNip = $_POST['company_nip'] ?? '6562276928';
$officeCode = $_POST['office_code'] ?? '1475';

if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && isset($_FILES['invoice_pdf'])) {
    $allInvoices = [];
    $rawText = '';

    $tmpNames = $_FILES['invoice_pdf']['tmp_name'];

    if (!is_array($tmpNames)) {
        $tmpNames = [$tmpNames];
    }

    foreach ($tmpNames as $tmpPath) {
        if (!is_uploaded_file($tmpPath)) {
            continue;
        }

        $invoices = parseInvoicesFromPdf($tmpPath, $companyNip);
        $rawText = $GLOBALS['last_pdf_text'] ?? $rawText;

        if (!empty($invoices)) {
            $allInvoices = array_merge($allInvoices, $invoices);
        }
    }

    if (empty($allInvoices)) {
        if ($rawText === '') {
            $error = 'Nie udało się odczytać żadnego tekstu z PDF. Upewnij się, że masz zainstalowane narzędzia OCR (pdftoppm, tesseract).';
        } else {
            $error = 'Nie udało się dopasować danych faktur do bieżących wzorców. Poniżej jest surowy tekst z PDF do analizy.';
            $debugText = $rawText;
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
        ];

        $invoiceData = $allInvoices;
        $xml = generateJpkFaXml($allInvoices, $meta);
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
    <h1>Konwerter faktur PDF → JPK XML</h1>
    <p>Wgraj fakturę w PDF, a aplikacja spróbuje wygenerować plik JPK_FA w XML na podstawie odczytanych danych.</p>

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
            <label for="invoice_pdf">Plik(i) faktur PDF</label>
            <input type="file" id="invoice_pdf" name="invoice_pdf[]" accept="application/pdf" multiple required>
        </div>
        <button type="submit">Generuj JPK XML</button>
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
