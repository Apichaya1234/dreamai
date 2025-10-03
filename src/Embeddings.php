<?php
/**
 * src/Embeddings.php
 * Handles creation and semantic search of vector embeddings.
 */
declare(strict_types=1);

namespace DreamAI;

class Embeddings
{
    private OpenAI $ai;
    private DB $db;

    public function __construct(OpenAI $ai, DB $db)
    {
        $this->ai = $ai;
        $this->db = $db;
    }

    /**
     * Finds the most semantically similar symbols from the lexicon for a given text.
     *
     * @param string $text The user's dream narrative.
     * @param int $limit The maximum number of symbols to return.
     * @return array The top matching lexicon entries.
     */
    public function getSimilarSymbols(string $text, int $limit = 3): array
    {
        // 1. Create a vector embedding for the user's input text.
        $res = $this->ai->embed($text);
        $inputVector = $res['data'][0]['embedding'] ?? null;

        if (!$inputVector) {
            return []; // Cannot proceed without an input vector
        }

        // 2. Fetch all lexicon entries that have pre-computed embeddings.
        $lexicon = $this->db->all('SELECT id, lemma, description, positive_interpretation, negative_interpretation, embedding FROM dream_lexicon WHERE embedding IS NOT NULL');

        $similarities = [];
        foreach ($lexicon as $entry) {
            // Unpack the stored binary vector into a PHP array of floats.
            $lexiconVector = self::unpackVector($entry['embedding']);
            if (!$lexiconVector) continue;

            // 3. Calculate cosine similarity between the input and each lexicon entry.
            $similarity = self::cosineSimilarity($inputVector, $lexiconVector);
            $similarities[] = [
                'similarity' => $similarity,
                'entry' => $entry,
            ];
        }

        // 4. Sort by similarity score in descending order.
        usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        // 5. Return the top N results.
        $topMatches = array_slice($similarities, 0, $limit);

        // Return only the entry data, not the similarity score itself.
        return array_map(fn($sim) => $sim['entry'], $topMatches);
    }

    // --- Vector Math Helpers ---

    private static function cosineSimilarity(array $vecA, array $vecB): float
    {
        $dotProduct = self::dotProduct($vecA, $vecB);
        $magnitudeA = self::magnitude($vecA);
        $magnitudeB = self::magnitude($vecB);

        if ($magnitudeA == 0 || $magnitudeB == 0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    private static function dotProduct(array $vecA, array $vecB): float
    {
        $product = 0.0;
        $count = count($vecA);
        for ($i = 0; $i < $count; $i++) {
            $product += $vecA[$i] * $vecB[$i];
        }
        return $product;
    }

    private static function magnitude(array $vec): float
    {
        return sqrt(self::dotProduct($vec, $vec));
    }

    /**
     * Packs a PHP array of floats into a binary string for database storage.
     * @param float[] $vector
     * @return string
     */
    public static function packVector(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * Unpacks a binary string from the database back into a PHP array of floats.
     * @param string|null $binaryVector
     * @return float[]|null
     */
    public static function unpackVector(?string $binaryVector): ?array
    {
        if ($binaryVector === null || $binaryVector === '') return null;
        $unpacked = unpack('f*', $binaryVector);
        return $unpacked ? array_values($unpacked) : null;
    }
}
