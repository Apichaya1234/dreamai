-- seed/lexicon_seed.sql
-- DreamAI — Lexicon seed (idempotent). Inserts base lemmas, synonyms, categories, and mappings.

START TRANSACTION;

-- ============================================================================
-- 1) LEXICON ENTRIES (INSERT IF NOT EXISTS)
--    lucky_numbers is a JSON column; store valid JSON text '[94,529,921]'
-- ============================================================================

-- กก
INSERT INTO dream_lexicon (lemma, description, positive_interpretation, negative_interpretation, lucky_numbers, culture_notes, language, source_tag)
SELECT 'กก',
       'ฝันถึงการกก (กกกอด กกไข่ กกลูก)',
       'จะมีบริวารดั่งใจปรารถนา',
       'เรื่องที่ทำอยู่อาจถูกดองหรือถูกบั่นทอน',
       '[94,529,921]',
       NULL,
       'th',
       'seed'
WHERE NOT EXISTS (SELECT 1 FROM dream_lexicon WHERE lemma = 'กก');

-- กฐิน
INSERT INTO dream_lexicon (lemma, description, positive_interpretation, negative_interpretation, lucky_numbers, culture_notes, language, source_tag)
SELECT 'กฐิน',
       'ฝันว่าร่วมงานทอดกฐิน',
       'จะมีจิตใจอิ่มเอิบ ปีติสุข',
       NULL,
       '[21,579,610]',
       NULL,
       'th',
       'seed'
WHERE NOT EXISTS (SELECT 1 FROM dream_lexicon WHERE lemma = 'กฐิน');

-- กงเกวียน
INSERT INTO dream_lexicon (lemma, description, positive_interpretation, negative_interpretation, lucky_numbers, culture_notes, language, source_tag)
SELECT 'กงเกวียน',
       '(กงกรรม) กงเกวียน',
       'ชอบชีวิตเรียบง่ายและยอมรับกฎสังสารวัฏ',
       NULL,
       NULL,
       NULL,
       'th',
       'seed'
WHERE NOT EXISTS (SELECT 1 FROM dream_lexicon WHERE lemma = 'กงเกวียน');

-- กงจักร
INSERT INTO dream_lexicon (lemma, description, positive_interpretation, negative_interpretation, lucky_numbers, culture_notes, language, source_tag)
SELECT 'กงจักร',
       'ฝันถึงกงจักร',
       NULL,
       'เตือนให้ระวังของมีคมหรือคนมีอำนาจ อย่าได้หลงนึกว่าเป็นดอกบัว',
       NULL,
       NULL,
       'th',
       'seed'
WHERE NOT EXISTS (SELECT 1 FROM dream_lexicon WHERE lemma = 'กงจักร');

-- กงล้อ
INSERT INTO dream_lexicon (lemma, description, positive_interpretation, negative_interpretation, lucky_numbers, culture_notes, language, source_tag)
SELECT 'กงล้อ',
       'ฝันเห็นกงล้อของโรงสี/วงล้อกำลังหมุน',
       NULL,
       'อาจเกิดความขัดแย้งถึงขั้นลงไม้ลงมือ หรือเสี่ยงอันตราย',
       NULL,
       NULL,
       'th',
       'seed'
WHERE NOT EXISTS (SELECT 1 FROM dream_lexicon WHERE lemma = 'กงล้อ');

-- ============================================================================
-- 2) SYNONYMS (INSERT IF NOT EXISTS, USING LEMMA LOOKUP)
-- ============================================================================

-- กก → (กกกอด, กกไข่, กกลูก)
INSERT INTO dream_synonyms (lexicon_id, term)
SELECT dl.id, 'กกกอด'
FROM dream_lexicon dl
WHERE dl.lemma='กก'
  AND NOT EXISTS (
    SELECT 1 FROM dream_synonyms s WHERE s.lexicon_id = dl.id AND s.term='กกกอด'
  );

INSERT INTO dream_synonyms (lexicon_id, term)
SELECT dl.id, 'กกไข่'
FROM dream_lexicon dl
WHERE dl.lemma='กก'
  AND NOT EXISTS (
    SELECT 1 FROM dream_synonyms s WHERE s.lexicon_id = dl.id AND s.term='กกไข่'
  );

INSERT INTO dream_synonyms (lexicon_id, term)
SELECT dl.id, 'กกลูก'
FROM dream_lexicon dl
WHERE dl.lemma='กก'
  AND NOT EXISTS (
    SELECT 1 FROM dream_synonyms s WHERE s.lexicon_id = dl.id AND s.term='กกลูก'
  );

-- กงเกวียน → (กงกรรม)
INSERT INTO dream_synonyms (lexicon_id, term)
SELECT dl.id, 'กงกรรม'
FROM dream_lexicon dl
WHERE dl.lemma='กงเกวียน'
  AND NOT EXISTS (
    SELECT 1 FROM dream_synonyms s WHERE s.lexicon_id = dl.id AND s.term='กงกรรม'
  );

-- กงล้อ → (วงล้อ)
INSERT INTO dream_synonyms (lexicon_id, term)
SELECT dl.id, 'วงล้อ'
FROM dream_lexicon dl
WHERE dl.lemma='กงล้อ'
  AND NOT EXISTS (
    SELECT 1 FROM dream_synonyms s WHERE s.lexicon_id = dl.id AND s.term='วงล้อ'
  );

-- ============================================================================
-- 3) CATEGORIES (UNIQUE name) + MAPPINGS (LEXICON↔CATEGORY)
-- ============================================================================

-- Ensure category names exist (idempotent thanks to UNIQUE on name)
INSERT IGNORE INTO dream_categories (name) VALUES
  ('ครอบครัว'),
  ('ความสัมพันธ์'),
  ('ศาสนา/บุญ'),
  ('หลักธรรม'),
  ('อาวุธ/อันตราย'),
  ('เครื่องจักร/ยานกล');

-- Mapping helper: INSERT if not exists using subselects

-- กก → ครอบครัว, ความสัมพันธ์
INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='ครอบครัว'
WHERE dl.lemma='กก'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='ความสัมพันธ์'
WHERE dl.lemma='กก'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

-- กฐิน → ศาสนา/บุญ
INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='ศาสนา/บุญ'
WHERE dl.lemma='กฐิน'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

-- กงเกวียน → หลักธรรม
INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='หลักธรรม'
WHERE dl.lemma='กงเกวียน'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

-- กงจักร → อาวุธ/อันตราย
INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='อาวุธ/อันตราย'
WHERE dl.lemma='กงจักร'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

-- กงล้อ → เครื่องจักร/ยานกล
INSERT INTO dream_lexicon_categories (lexicon_id, category_id)
SELECT dl.id, dc.id
FROM dream_lexicon dl
JOIN dream_categories dc ON dc.name='เครื่องจักร/ยานกล'
WHERE dl.lemma='กงล้อ'
  AND NOT EXISTS (
    SELECT 1 FROM dream_lexicon_categories x
    WHERE x.lexicon_id = dl.id AND x.category_id = dc.id
  );

COMMIT;

-- END OF FILE
