<?php

namespace Tests\Unit;

use App\Services\Qda\CodeMeta;
use App\Services\Qda\Vocabulary;
use App\Services\Qda\VocabularySet;
use PHPUnit\Framework\TestCase;

class CodeMetaTest extends TestCase
{
    private function vocabulary(): VocabularySet
    {
        $data = Vocabulary::defaults();
        $data['code_fields'][] = ['key' => 'core', 'label' => 'Core', 'type' => 'boolean'];
        $data['code_fields'][] = ['key' => 'level', 'label' => 'Level', 'type' => 'choice', 'options' => [['key' => 'semantic', 'label' => 'Semantic']]];

        return new VocabularySet(Vocabulary::normalize($data));
    }

    public function test_code_meta_normalisation_drops_unknown_and_empty(): void
    {
        $meta = CodeMeta::normalize([
            'definition' => '  Talk about leaving  ',
            'origin' => 'a_priori',
            'level' => 'nonsense',
            'status' => 'stable',
            'core' => true,
            'evil' => '<script>',
            'properties' => [['name' => 'intensity', 'low' => 'weak', 'high' => 'strong'], ['name' => '']],
        ], $this->vocabulary());
        $this->assertSame([
            'definition' => 'Talk about leaving',
            'status' => 'stable',
            'properties' => [['name' => 'intensity', 'low' => 'weak', 'high' => 'strong']],
            'origin' => 'a_priori',
            'core' => true,
        ], $meta);
        $this->assertNull(CodeMeta::normalize(['definition' => '   '], $this->vocabulary()));
    }

    public function test_fields_follow_the_project_vocabulary(): void
    {
        $plain = new VocabularySet(Vocabulary::normalize(Vocabulary::defaults()));
        $this->assertSame(['origin' => 'in_vivo'], CodeMeta::normalize(['origin' => 'in_vivo', 'core' => true, 'level' => 'semantic'], $plain));
        $rules = CodeMeta::rules($plain);
        $this->assertArrayHasKey('meta.origin', $rules);
        $this->assertArrayNotHasKey('meta.core', $rules);
    }

    public function test_defaults_name_no_methodology(): void
    {
        $text = strtolower(json_encode(Vocabulary::defaults()));
        foreach (['strauss', 'corbin', 'braun', 'clarke', 'huberman', 'paradigm', 'axial', 'grounded theory'] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
    }
}
