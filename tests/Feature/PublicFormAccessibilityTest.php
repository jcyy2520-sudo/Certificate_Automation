<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use App\Models\Webinar;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class PublicFormAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create(['created_by' => $this->admin->id, 'requires_verification' => false]);
        $this->form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Accessible registration', 'status' => 'published']);
    }

    public function test_dynamic_prompts_name_text_controls_and_group_their_answer_choices(): void
    {
        $answers = [];
        foreach (['text', 'textarea', 'email', 'number', 'date', 'radio', 'select', 'checkbox'] as $type) {
            $field = $this->form->fields()->create(['key' => 'prompt_'.$type, 'label' => 'Prompt for '.$type, 'field_type' => $type, 'options' => ['First choice', 'Second choice']]);
            $answers[] = ['name' => 'fields['.$field->id.']', 'prompt' => 'Prompt for '.$type, 'choices' => in_array($type, ['radio', 'select', 'checkbox'], true)];
        }
        foreach (['text', 'multiple_choice', 'true_false'] as $type) {
            $question = $this->form->questions()->create(['prompt' => 'Question for '.$type, 'question_type' => $type, 'points' => 1]);
            $question->choices()->create(['label' => 'Quiz choice', 'is_correct' => true, 'sort_order' => 1]);
            $answers[] = ['name' => 'questions['.$question->id.']', 'prompt' => 'Question for '.$type, 'choices' => $type !== 'text'];
        }

        $response = $this->get($this->form->shareUrl())->assertOk();
        $xpath = $this->document($response->getContent());
        foreach ($answers as $answer) {
            $controls = $xpath->query('//form[@data-survey]//*[@name="'.$answer['name'].'"]');
            $this->assertGreaterThan(0, $controls->length);
            foreach ($controls as $control) {
                $legend = $xpath->query('ancestor::fieldset[1]/legend', $control)->item(0);
                $this->assertInstanceOf(DOMElement::class, $legend, $answer['prompt'].' needs a native question group.');
                $this->assertStringContainsString($answer['prompt'], $legend->textContent);
                if (! $answer['choices']) {
                    $this->assertNotSame('', $legend->getAttribute('id'));
                    $this->assertContains($legend->getAttribute('id'), explode(' ', $control->getAttribute('aria-labelledby')));
                } else {
                    $label = $xpath->query('ancestor::label[1]', $control)->item(0);
                    $this->assertInstanceOf(DOMElement::class, $label);
                    $this->assertNotSame('', trim($label->textContent));
                }
                $this->assertTrue($control->hasAttribute('data-answerable'));
            }
        }
    }

    public function test_answer_help_and_errors_remain_associated_with_retained_answers(): void
    {
        $field = $this->form->fields()->create(['key' => 'comment', 'label' => 'Session comments', 'field_type' => 'textarea', 'help_text' => 'Describe your experience.']);
        $radio = $this->form->fields()->create(['key' => 'rating', 'label' => 'Session rating', 'field_type' => 'radio', 'options' => ['Useful', 'Very useful'], 'help_text' => 'Choose one rating.']);
        $question = $this->form->questions()->create(['prompt' => 'What did you learn?', 'question_type' => 'text', 'points' => 1]);
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['fields.'.$field->id => 'Please expand your comments.', 'fields.'.$radio->id => 'Please check your rating.', 'questions.'.$question->id => 'Please expand your answer.']));
        $response = $this->withSession(['errors' => $errors, '_old_input' => ['fields' => [$field->id => 'Retained comments', $radio->id => 'Very useful'], 'questions' => [$question->id => 'Retained learning']]])
            ->get($this->form->shareUrl())->assertOk();
        $xpath = $this->document($response->getContent());

        foreach ([
            ['name' => 'fields['.$field->id.']', 'help' => 'Describe your experience.', 'error' => 'Please expand your comments.'],
            ['name' => 'fields['.$radio->id.']', 'help' => 'Choose one rating.', 'error' => 'Please check your rating.'],
            ['name' => 'questions['.$question->id.']', 'help' => '', 'error' => 'Please expand your answer.'],
        ] as $answer) {
            foreach ($xpath->query('//form[@data-survey]//*[@name="'.$answer['name'].'"]') as $control) {
                $this->assertSame('true', $control->getAttribute('aria-invalid'));
                $descriptions = [];
                foreach (explode(' ', $control->getAttribute('aria-describedby')) as $id) {
                    $description = $xpath->query('//*[@id="'.$id.'"]')->item(0);
                    $this->assertInstanceOf(DOMElement::class, $description);
                    $descriptions[] = trim($description->textContent);
                }
                $this->assertContains($answer['error'], $descriptions);
                if ($answer['help'] !== '') {
                    $this->assertContains($answer['help'], $descriptions);
                }
            }
        }
        $this->assertSame('Retained comments', $xpath->query('//textarea[@name="fields['.$field->id.']"]')->item(0)->textContent);
        $this->assertSame('Retained learning', $xpath->query('//textarea[@name="questions['.$question->id.']"]')->item(0)->textContent);
        $this->assertSame('Very useful', $xpath->query('//input[@name="fields['.$radio->id.']" and @checked]')->item(0)->getAttribute('value'));
    }

    public function test_administrator_preview_uses_readable_copy_and_keeps_submissions_disabled(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.forms.preview', [$this->form->webinar, $this->form]))->assertOk();
        $response->assertSee('Administrator preview: Submissions are disabled.');
        $xpath = $this->document($response->getContent());
        $button = $xpath->query('//form[@data-survey]//button')->item(0);
        $this->assertTrue($button->hasAttribute('disabled'));
        $this->assertSame('Preview: submissions disabled', trim($button->textContent));
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
