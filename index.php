<?php
/**
 * Simple RAG System for cPanel
 * Single file solution using OpenAI API directly (no Neuron AI dependency)
 * 
 * Setup:
 * 1. Upload this file to your cPanel
 * 2. Create 'docs' folder in same directory
 * 3. Upload your documentation to 'docs' folder
 * 4. Set your API key below
 * 5. First visit: add ?setup=1 to generate embeddings
 */
// ==================== CONFIGURATION ====================
define('OPENAI_API_KEY', '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');
define('MODEL', 'gpt-4o-mini'); // or gpt-4o
define('EMBEDDING_MODEL', 'text-embedding-3-small');
define('DOCS_DIR', __DIR__ . '/docs');
define('STORAGE_FILE', __DIR__ . '/embeddings.json');
define('MAX_DOCS_TO_RETRIEVE',
3);

// ==================== FUNCTIONS ====================

function callOpenAI($endpoint, $data) {
    $ch = curl_init("https://api.openai.com/v1/{$endpoint}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER,
    [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("API Error: HTTP {$httpCode} - {$response}");
    }
    
    return json_decode($response, true);
}

function getEmbedding($text) {
    $response = callOpenAI('embeddings',
    [
        'model' => EMBEDDING_MODEL,
        'input' => $text
    ]);
    return $response['data'
    ][
        0
    ]['embedding'
    ];
}

function cosineSimilarity($vec1, $vec2) {
    $dotProduct = 0;
    $mag1 = 0;
    $mag2 = 0;
    
    for ($i = 0; $i < count($vec1); $i++) {
        $dotProduct += $vec1[$i
        ] * $vec2[$i
        ];
        $mag1 += $vec1[$i
        ] * $vec1[$i
        ];
        $mag2 += $vec2[$i
        ] * $vec2[$i
        ];
    }
    
    return $dotProduct / (sqrt($mag1) * sqrt($mag2));
}

function loadDocuments($dir) {
    $documents = [];
    $files = glob($dir . '/*.{txt,md}', GLOB_BRACE);
    
    foreach ($files as $file) {
        $content = file_get_contents($file);
        if ($content) {
            $documents[] = [
                'file' => basename($file),
                'content' => $content
            ];
        }
    }
    
    return $documents;
}

function generateEmbeddings() {
    if (!is_dir(DOCS_DIR)) {
        mkdir(DOCS_DIR, 0755, true);
        return ['error' => 'Created docs folder. Please upload your documentation files there.'];
    }
    
    $documents = loadDocuments(DOCS_DIR);
    
    if (empty($documents)) {
        return ['error' => 'No documents found. Upload .txt or .md files to the docs folder.'];
    }
    
    $embeddings = [];
    foreach ($documents as $doc) {
        try {
            $embeddings[] = [
                'file' => $doc['file'],
                'content' => $doc['content'],
                'embedding' => getEmbedding($doc['content'])
            ];
        } catch (Exception $e) {
            return ['error' => 'Embedding failed: ' . $e->getMessage()];
        }
    }
    
    file_put_contents(STORAGE_FILE, json_encode($embeddings));
    
    return [
        'success' => true,
        'count' => count($embeddings),
        'message' => 'Successfully generated embeddings for ' . count($embeddings) . ' documents'
    ];
}

function findRelevantDocs($question) {
    if (!file_exists(STORAGE_FILE)) {
        return [];
    }
    
    $embeddings = json_decode(file_get_contents(STORAGE_FILE), true);
    $queryEmbedding = getEmbedding($question);
    
    // Calculate similarities
    $similarities = [];
    foreach ($embeddings as $doc) {
        $similarities[] = [
            'file' => $doc['file'],
            'content' => $doc['content'],
            'similarity' => cosineSimilarity($queryEmbedding, $doc['embedding'])
        ];
    }
    
    // Sort by similarity
    usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
    
    // Return top N documents
    return array_slice($similarities, 0, MAX_DOCS_TO_RETRIEVE);
}

function answerQuestion($question) {
    $relevantDocs = findRelevantDocs($question);
    
    if (empty($relevantDocs)) {
        return "No relevant documentation found. Please run setup first by visiting ?setup=1";
    }
    
    // Build context from relevant documents
    $context = "Use the following documentation to answer the question:\n\n";
    foreach ($relevantDocs as $doc) {
        $context .= "Document: {$doc['file']}\n{$doc['content']}\n\n";
    }
    
    $messages = [
        [
            'role' => 'system',
            'content' => 'You are a helpful documentation assistant. Answer questions based only on the provided documentation. Be concise and accurate.'
        ],
        [
            'role' => 'user',
            'content' => $context . "\nQuestion: " . $question
        ]
    ];
    
    try {
        $response = callOpenAI('chat/completions', [
            'model' => MODEL,
            'messages' => $messages,
            'max_tokens' => 1000,
            'temperature' => 0.7
        ]);
        
        return $response['choices'][0]['message']['content'];
    } catch (Exception $e) {
        return "Error: " . $e->getMessage();
    }
}

// ==================== MAIN LOGIC ====================

session_start();

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'];
$_SESSION['requests'] = $_SESSION['requests'] ?? [];
$_SESSION['requests'] = array_filter($_SESSION['requests'], fn($t) => $t > time() - 3600);

if (count($_SESSION['requests']) >= 60) {
    die('Rate limit exceeded. Please try again in an hour.');
}

$_SESSION['requests'][] = time();

// Handle setup
if (isset($_GET['setup'])) {
    $result = generateEmbeddings();
    if (isset($result['error'])) {
        $setupMessage = '❌ ' . $result['error'];
    } else {
        $setupMessage = '✅ ' . $result['message'];
    }
}

// Handle question
$answer = null;
$question = $_POST['question'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($question)) {
    $answer = answerQuestion($question);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentation Assistant</title>
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --bg: #f9fafb;
        --card-bg: #ffffff;
        --accent: #6366f1;
        --accent-hover: #4f46e5;
        --border: #e5e7eb;
        --text: #111827;
        --muted: #6b7280;
        --radius: 14px;
        --transition: 0.2s ease;
    }
    body {
        font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        background-color: var(--bg);
        color: var(--text);
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 100vh;
        padding: 30px;
    }
    .container {
        width: 100%;
        max-width: 750px;
        background-color: var(--card-bg);
        border-radius: var(--radius);
        box-shadow: 0 8px 30px rgba(0,0,0,0.05);
        padding: 40px 45px;
        transition: var(--transition);
    }
    h1 {
        font-size: 1.8rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .subtitle {
        font-size: 0.95rem;
        color: var(--muted);
        margin-bottom: 30px;
    }
    textarea {
        width: 100%;
        min-height: 120px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 16px;
        font-size: 0.95rem;
        font-family: inherit;
        background-color: #fafafa;
        color: var(--text);
        resize: vertical;
        transition: border var(--transition), box-shadow var(--transition);
    }
    textarea:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
    }
    button {
        background-color: var(--accent);
        color: white;
        border: none;
        border-radius: var(--radius);
        padding: 12px 28px;
        font-size: 0.95rem;
        font-weight: 600;
        margin-top: 18px;
        cursor: pointer;
        transition: background var(--transition), transform var(--transition);
    }
    button:hover {
        background-color: var(--accent-hover);
        transform: translateY(-1px);
    }
    .answer-box {
        margin-top: 30px;
        background-color: #f8fafc;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 22px 24px;
        font-size: 0.95rem;
        line-height: 1.7;
        color: #1f2937;
        white-space: pre-wrap;
    }
    .label {
        font-weight: 600;
        color: var(--accent);
        margin-bottom: 10px;
        font-size: 1rem;
    }
    .instructions, .setup-notice {
        border-radius: var(--radius);
        padding: 18px 20px;
        font-size: 0.95rem;
        line-height: 1.6;
        margin-bottom: 25px;
    }
    .instructions {
        background-color: #eef2ff;
        border: 1px solid #c7d2fe;
        color: #3730a3;
    }
    .setup-notice {
        border: 1px solid var(--border);
    }
    .setup-success {
        background-color: #ecfdf5;
        border-color: #34d399;
        color: #065f46;
    }
    .setup-error {
        background-color: #fef2f2;
        border-color: #fca5a5;
        color: #991b1b;
    }
    ol { margin-top: 10px; padding-left: 18px; }
    li { margin-bottom: 6px; }
    .setup-link {
        display: inline-block;
        background: var(--accent);
        color: #fff;
        text-decoration: none;
        border-radius: var(--radius);
        padding: 8px 16px;
        font-weight: 500;
        margin-top: 12px;
        transition: var(--transition);
    }
    .setup-link:hover { background: var(--accent-hover); }
    .footer {
        margin-top: 35px;
        padding-top: 15px;
        border-top: 1px solid var(--border);
        text-align: center;
        font-size: 0.85rem;
        color: var(--muted);
    }
    @media (max-width: 600px) {
        .container { padding: 30px 25px; }
        h1 { font-size: 1.6rem; }
    }
</style>

</head>
<body>
    <div class="container">
        <h1>📚 BIO Assistant</h1>
        <p class="subtitle">AI-powered BIO search using RAG</p>
        
        <?php if (!file_exists(STORAGE_FILE)): ?>
            <div class="instructions">
                <strong>🚀 First Time Setup:</strong>
                <ol>
                    <li>Edit this file and add your OpenAI API key (line 16)</li>
                    <li>Create a folder named 'docs' in the same directory</li>
                    <li>Upload your documentation files (.txt or .md) to the docs folder</li>
                    <li><a href="?setup=1" class="setup-link">Click here to generate embeddings</a></li>
                </ol>
            </div>
        <?php endif; ?>
        
        <?php if (isset($setupMessage)): ?>
            <div class="setup-notice <?php echo strpos($setupMessage, '✅') !== false ? 'setup-success' : 'setup-error'; ?>">
                <?php echo htmlspecialchars($setupMessage); ?>
                <?php if (strpos($setupMessage, '✅') !== false): ?>
                    <br><br>You can now ask questions below!
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (file_exists(STORAGE_FILE)): ?>
            <form method="POST">
                <textarea 
                    name="question" 
                    placeholder="Ask a question about your documentation..."
                    required
                ><?php echo htmlspecialchars($question); ?></textarea>
                <button type="submit">Get Answer</button>
            </form>
            
            <?php if ($answer): ?>
                <div class="answer-box">
                    <div class="label">✨ Answer</div>
                    <?php echo htmlspecialchars($answer); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="footer">
            
        </div>
    </div>
</body>
</html>