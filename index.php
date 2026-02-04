<?php
// --- 1. 環境設定 ---
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); 
ini_set('display_errors', 0);
set_time_limit(120); // 処理時間を120秒に延長

// --- 2. 設定情報 ---
$apiKey = 'AlQFjz0JcRNSLRw20hWIA0NOCuiAGAuSP4HruBOb8cHtIPoLA0wXJQQJ99CBACi0881XJ3w3AAALACOGaV2b';
$endpoint = 'https://receipt-ai-vision-01.cognitiveservices.azure.com/';
$ocrLogFile = 'ocr.log';
$csvFile = 'result.csv';

// データベース設定
$dbServer = "receipt-sql-server-24jn0245.database.windows.net";
$dbName = "receipt-db";
$dbUser = "jnsql";
$dbPass = 'Pa$$word1234';

$displayResults = [];
$errorMsg = "";

// --- 3. メインロジック ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        $conn = new PDO("sqlsrv:server=$dbServer;Database=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($_FILES['files']['tmp_name'] as $tmpName) {
            if (empty($tmpName)) continue;

            // A. Azure AI呼び出し
            $analyzeUrl = $endpoint . "documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
            $imgData = file_get_contents($tmpName);

            $ch = curl_init($analyzeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);

            preg_match('/Operation-Location: (.*)/i', $response, $matchesURL);
            $resultUrl = isset($matchesURL[1]) ? trim($matchesURL[1]) : null;
            curl_close($ch);

            if (!$resultUrl) continue;

            // B. 結果のポーリング（最大30回試行）
            $isDone = false;
            $statusData = [];
            $retryCount = 0;
            while (!$isDone && $retryCount < 30) {
                sleep(2);
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') $isDone = true;
                curl_close($ch2);
                $retryCount++;
            }

            // C. ログ記録
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // D. 解析とDB保存
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            $stmt = $conn->prepare("INSERT INTO receipts (item_name, price, is_total) VALUES (:name, :price, :is_total)");

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? '不明な商品';
                // ファミマのレシート特有の記号を掃除
                $cleanName = str_replace(['轻', '◎', ' ', '　', '*'], '', $rawName);
                $price = (int)($item['valueObject']['TotalPrice']['valueNumber'] ?? 0);

                if ($price > 0) {
                    $stmt->execute(['name' => $cleanName, 'price' => $price, 'is_total' => 0]);
                    $displayResults[] = ['name' => $cleanName, 'price' => $price];
                }
            }
            
            // 合計
            $stmt->execute(['name' => '合計', 'price' => (int)$totalAmount, 'is_total' => 1]);
            $displayResults[] = ['name' => '合計', 'price' => (int)$totalAmount];
        }

        // E. CSV生成
        $fp = fopen($csvFile, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); 
        foreach ($displayResults as $row) {
            fputcsv($fp, [$row['name'], $row['price']]);
        }
        fclose($fp);

    } catch (Exception $e) {
        $errorMsg = "エラーが発生しました: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>レシートOCRシステム</title>
    <style>
        body { font-family: sans-serif; margin: 40px; line-height: 1.6; }
        .result-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .result-table th, .result-table td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        .total-row { background-color: #f9f9f9; font-weight: bold; }
        .btn { padding: 10px 20px; background: #0078d4; color: white; border: none; cursor: pointer; }
        .error { color: red; }
    </style>
</head>
<body>
    <h1>レシート自動解析システム (Azure AI)</h1>
    
    <form action="" method="post" enctype="multipart/form-data">
        <p>レシート画像を選択してください（複数可）：</p>
        <input type="file" name="files[]" multiple accept="image/*">
        <br><br>
        <button type="submit" class="btn">アップロードして解析開始</button>
    </form>

    <?php if ($errorMsg): ?>
        <p class="error"><?= htmlspecialchars($errorMsg) ?></p>
    <?php endif; ?>

    <?php if (!empty($displayResults)): ?>
        <h2>解析結果：</h2>
        <table class="result-table">
            <thead>
                <tr><th>商品名</th><th>金額 (円)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($displayResults as $item): ?>
                    <tr class="<?= $item['name'] == '合計' ? 'total-row' : '' ?>">
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= number_format($item['price']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p><a href="result.csv">CSVファイルをダウンロード</a></p>
    <?php endif; ?>
</body>
</html>