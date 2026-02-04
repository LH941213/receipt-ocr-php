<?php
// --- 1. 環境設定とエラー処理 ---
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);
set_time_limit(120); 

// --- 2. 設定情報 ---
$apiKey = '13xSqpsRYAH9oeZG5N5XsRwcSwyegTHtni3Axisx0b2RMgWnpZNPJQQJ99CBACi0881XJ3w3AAALACOGRyKN';
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

// --- 3. コアロジック ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        // A. データベース接続とテーブル作成
        $conn = new PDO("sqlsrv:server=$dbServer;Database=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // テーブルが存在しない場合は作成
        $conn->exec("IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='receipts' AND xtype='U')
                    CREATE TABLE receipts (
                        id INT IDENTITY(1,1) PRIMARY KEY,
                        item_name NVARCHAR(255),
                        price INT,
                        is_total INT,
                        created_at DATETIME DEFAULT GETDATE()
                    )");

        foreach ($_FILES['files']['tmp_name'] as $tmpName) {
            if (empty($tmpName)) continue;

            $imgData = file_get_contents($tmpName);
            $cleanEndpoint = rtrim(trim($endpoint), '/');
            // 成功したパスを使用
            $analyzeUrl = $cleanEndpoint . "/formrecognizer/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";

            $ch = curl_init($analyzeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream', 'Ocp-Apim-Subscription-Key: ' . trim($apiKey)]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 202) throw new Exception("Azure AI 接続エラー。ステータスコード: $httpCode");

            preg_match('/Operation-Location: (.*)/i', $response, $matchesURL);
            $resultUrl = isset($matchesURL[1]) ? trim($matchesURL[1]) : null;
            curl_close($ch);

            // B. 解析結果の取得（ポーリング）
            $isDone = false; $statusData = []; $retry = 0;
            while (!$isDone && $retry < 30) {
                sleep(2);
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . trim($apiKey)]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') $isDone = true;
                curl_close($ch2);
                $retry++;
            }

            // ログの書き込み
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // C. データの解析とDB保存
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            $stmt = $conn->prepare("INSERT INTO receipts (item_name, price, is_total) VALUES (:name, :price, :is_total)");

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? '不明な商品';
                // 不要な文字を削除（軽、◎、空白、*）
                $cleanName = str_replace(['轻', '◎', ' ', '　', '*'], '', $rawName);
                $price = (int)($item['valueObject']['TotalPrice']['valueNumber'] ?? 0);

                if ($price > 0) {
                    $stmt->execute(['name' => $cleanName, 'price' => $price, 'is_total' => 0]);
                    $displayResults[] = ['name' => $cleanName, 'price' => $price];
                }
            }
            // 合計行の追加
            $stmt->execute(['name' => '合計', 'price' => (int)$totalAmount, 'is_total' => 1]);
            $displayResults[] = ['name' => '合計', 'price' => (int)$totalAmount];
        }

        // D. CSVの生成
        $fp = fopen($csvFile, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); 
        foreach ($displayResults as $row) { fputcsv($fp, [$row['name'], $row['price']]); }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>レシートOCRシステム - Azure AI & SQL</title>
    <style>
        body { font-family: "Helvetica Neue", Arial, "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif; margin: 40px; background-color: #f4f7f9; color: #333; }
        .container { max-width: 900px; margin: auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #0078d4; border-bottom: 2px solid #0078d4; padding-bottom: 10px; }
        .table { width: 100%; border-collapse: collapse; margin-top: 25px; }
        .table th, .table td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        .table th { background-color: #f8f9fa; }
        .total-row { font-weight: bold; background-color: #e7f3ff; }
        .error { color: #a4373a; background: #fde7e9; padding: 15px; border: 1px solid #a4373a; border-radius: 4px; margin-bottom: 20px; }
        .upload-section { background: #f0f4f8; padding: 20px; border-radius: 4px; margin-bottom: 30px; }
        .btn { background-color: #0078d4; color: white; padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background-color: #005a9e; }
        .links { margin-top: 20px; display: flex; gap: 20px; }
        .links a { color: #0078d4; text-decoration: none; font-weight: bold; }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>レシート自動解析システム</h1>
        <p>Azure AI Vision を使用してファミリーマートのレシートを解析し、データベースへ保存します。</p>

        <?php if ($errorMsg): ?>
            <div class="error"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <div class="upload-section">
            <form method="post" enctype="multipart/form-data">
                <label>レシート画像を選択（複数可）:</label><br><br>
                <input type="file" name="files[]" multiple accept="image/*">
                <br><br>
                <button type="submit" class="btn">アップロードして解析を実行</button>
            </form>
        </div>

        <?php if (!empty($displayResults)): ?>
            <h2>解析結果</h2>
            <table class="table">
                <thead>
                    <tr><th>商品名</th><th>金額 (円)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($displayResults as $res): ?>
                        <tr class="<?= $res['name'] == '合計' ? 'total-row' : '' ?>">
                            <td><?= htmlspecialchars($res['name']) ?></td>
                            <td><?= number_format($res['price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="links">
                <a href="result.csv">📊 CSVファイルをダウンロード</a>
                <a href="ocr.log" target="_blank">📄 ocr.logを表示</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>