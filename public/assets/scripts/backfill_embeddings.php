<?php
/**
 * scripts/backfill_embeddings.php
 * A command-line script to generate and save embeddings for existing lexicon entries.
 *
 * How to run:
 * 1. Open your terminal or command prompt.
 * 2. Navigate to the root directory of your project (the one containing 'src', 'public', etc.).
 * 3. Run the script using the command: `php scripts/backfill_embeddings.php`
 *
 * NOTE: This can be a long-running process depending on the size of your lexicon
 * and the speed of the OpenAI API.
 */

declare(strict_types=1);

// Set a long execution time for this script
ini_set('max_execution_time', '3600'); // 1 hour
echo "Starting Embedding Backfill Script...\n";

// Bootstrap the application to get access to classes and config
require_once __DIR__ . '/../bootstrap.php';

use DreamAI\DB;
use DreamAI\OpenAI;
use DreamAI\Embeddings;

try {
    $db = new DB($config['db']);
    $ai = new OpenAI($config['openai']);
    
    // Fetch all lexicon entries that do NOT have an embedding yet.
    $entriesToProcess = $db->all('SELECT id, lemma FROM dream_lexicon WHERE embedding IS NULL OR embedding = ""');
    
    if (empty($entriesToProcess)) {
        echo "✅ All lexicon entries already have embeddings. No action needed.\n";
        exit;
    }

    echo "Found " . count($entriesToProcess) . " entries to process.\n";
    $processedCount = 0;
    $errorCount = 0;

    foreach ($entriesToProcess as $entry) {
        $id = $entry['id'];
        $textToEmbed = $entry['lemma'];

        try {
            echo "Processing ID: {$id} ('{$textToEmbed}')... ";
            
            // 1. Call the OpenAI API to get the embedding vector.
            $embeddingResult = $ai->embed($textToEmbed);
            $vector = $embeddingResult['data'][0]['embedding'] ?? null;

            if ($vector) {
                // 2. Pack the vector into a binary format for database storage.
                $packedVector = Embeddings::packVector($vector);

                // 3. Update the database row with the new embedding.
                $db->execStmt('UPDATE dream_lexicon SET embedding = ? WHERE id = ?', [$packedVector, $id]);
                
                echo "Success.\n";
                $processedCount++;
            } else {
                echo "Failed to get vector from API.\n";
                $errorCount++;
            }
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . "\n";
            $errorCount++;
        }
        
        // Optional: Add a small delay to avoid hitting API rate limits.
        usleep(200000); // 200 milliseconds
    }

    echo "----------------------------------------\n";
    echo "Backfill Complete.\n";
    echo "Successfully processed: {$processedCount}\n";
    echo "Errors encountered: {$errorCount}\n";

} catch (\Throwable $e) {
    echo "A critical error occurred: " . $e->getMessage() . "\n";
}
