<?php
namespace Tests\Unit;
use App\Support\Normalizer; use PHPUnit\Framework\TestCase;
class NormalizerTest extends TestCase {
 public function test_transaction_ids_are_global_normalization_friendly(): void { $this->assertSame('FT2600123',Normalizer::transactionId(' ft26-001 23 ')); }
 public function test_masked_account_runs_do_not_assume_mask_length_equals_hidden_digits(): void { $this->assertTrue(Normalizer::maskedAccountMatches('1000***6273','100012346273')); $this->assertTrue(Normalizer::maskedAccountMatches('1000***6273','100099996273')); $this->assertFalse(Normalizer::maskedAccountMatches('1000***6273','999912346273')); }
 public function test_account_holder_name_normalization_tolerates_punctuation(): void { $this->assertGreaterThanOrEqual(.9,Normalizer::nameSimilarity('ABC TRADING P.L.C.','ABC Trading PLC')); }
}
