<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AI\AiException;
use App\Services\AI\GeminiClient;
use App\Services\AI\GroqClient;
use App\Services\AI\OpenRouterClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SRS Future Enhancements — AI Features.
 *
 * POST /ai/generate-questions  — generate MCQ/T-F/short-answer from topic or uploaded file
 * POST /ai/tutor               — conversational tutor (Gemini or Groq, configurable)
 * POST /ai/grade               — AI-assisted assignment grading
 *
 * Provider selection: env AI_QUESTION_PROVIDER / AI_TUTOR_PROVIDER (gemini|groq|openrouter)
 */
class AiController extends Controller
{
    private function questionProvider(): GeminiClient|GroqClient|OpenRouterClient
    {
        return match (config('services.ai.question_provider', 'gemini')) {
            'groq'       => GroqClient::fromConfig(),
            'openrouter' => OpenRouterClient::fromConfig(),
            default      => GeminiClient::fromConfig(),
        };
    }

    private function tutorProvider(): GeminiClient|GroqClient|OpenRouterClient
    {
        return match (config('services.ai.tutor_provider', 'gemini')) {
            'groq'       => GroqClient::fromConfig(),
            'openrouter' => OpenRouterClient::fromConfig(),
            default      => GeminiClient::fromConfig(),
        };
    }

    /** POST /api/v1/ai/generate-questions */
    public function generateQuestions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source_type'   => ['required', 'in:text,topic,file'],
            'text'          => ['required_if:source_type,text', 'nullable', 'string', 'max:20000'],
            'topic'         => ['required_if:source_type,topic', 'nullable', 'string', 'max:500'],
            'file'          => ['required_if:source_type,file', 'nullable', 'file', 'mimes:pdf,txt,docx', 'max:10240'],
            'question_type' => ['required', 'in:multiple_choice,true_false,short_answer,fill_in_blank,mixed'],
            'count'         => ['required', 'integer', 'min:1', 'max:20'],
            'difficulty'    => ['required', 'in:easy,medium,hard,mixed'],
            'language'      => ['required', 'in:en,sw'],
        ]);

        // Extract text source
        $sourceText = '';
        if ($data['source_type'] === 'file' && $request->hasFile('file')) {
            $sourceText = $this->extractFileText($request->file('file'));
        } elseif ($data['source_type'] === 'text') {
            $sourceText = $data['text'] ?? '';
        } else {
            $sourceText = "Topic: " . ($data['topic'] ?? '');
        }

        $lang = $data['language'] === 'sw' ? 'Swahili (Kiswahili)' : 'English';
        $type = $data['question_type'];
        $count = (int) $data['count'];
        $diff = $data['difficulty'];

        $typeInstructions = match ($type) {
            'multiple_choice' => 'Multiple Choice Questions (MCQ) with exactly 4 options (A, B, C, D). Mark exactly one correct.',
            'true_false'      => 'True/False questions. Options must be exactly ["True", "False"].',
            'short_answer'    => 'Short answer questions requiring 1-3 sentence responses. Provide accept_keywords array.',
            'fill_in_blank'   => 'Fill-in-the-blank. Use ______ for the blank. Provide the exact answer.',
            'mixed'           => 'Mix of MCQ, True/False, and Short Answer question types.',
            default           => 'Multiple Choice Questions with 4 options.',
        };

        $prompt = <<<PROMPT
You are an expert educational content creator for a professional LMS platform (SAFCO FINTECH LMS).

Generate exactly {$count} {$typeInstructions} in {$lang} language.
Difficulty level: {$diff}.

Source material:
---
{$sourceText}
---

Return ONLY a valid JSON object with this exact structure (no markdown, no code fences):
{
  "questions": [
    {
      "text": "Question text here",
      "type": "multiple_choice",
      "options": [
        {"label": "Option A text", "is_correct": false},
        {"label": "Option B text", "is_correct": true},
        {"label": "Option C text", "is_correct": false},
        {"label": "Option D text", "is_correct": false}
      ],
      "correct_answer": "Option B text",
      "accept_keywords": [],
      "explanation": "Brief explanation of the correct answer",
      "difficulty": "{$diff}",
      "points": 10,
      "time_limit_seconds": 30,
      "tags": ["relevant", "tags"]
    }
  ]
}

Rules:
- For true_false: options array must have exactly 2 items with labels "True" and "False"
- For short_answer: options array should be empty [], correct_answer is a sample answer, accept_keywords is array of key terms
- For fill_in_blank: text contains ______ placeholder, correct_answer is the exact word/phrase
- Make questions directly relevant to the source material
- Ensure questions are educationally valuable and professionally worded
PROMPT;

        try {
            $provider = $this->questionProvider();
            $raw = $this->callProvider($provider, $prompt, true);
            $parsed = json_decode($raw, true);

            if (!isset($parsed['questions']) || !is_array($parsed['questions'])) {
                // Try to extract JSON from the response
                if (preg_match('/\{.*"questions".*\}/s', $raw, $m)) {
                    $parsed = json_decode($m[0], true);
                }
            }

            if (!isset($parsed['questions'])) {
                Log::warning('ai.generate_questions.parse_failed', ['raw' => substr($raw, 0, 500)]);
                return $this->error('AI returned an unexpected format. Please try again.', 422);
            }

            return $this->success(['questions' => $parsed['questions']]);
        } catch (AiException $e) {
            Log::error('ai.generate_questions.failed', ['error' => $e->getMessage()]);
            return $this->error('AI service unavailable. Please try again later.', 503);
        }
    }

    /** POST /api/v1/ai/tutor */
    public function tutor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages'         => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role'  => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:5000'],
            'context.course'   => ['nullable', 'string', 'max:200'],
            'context.lesson'   => ['nullable', 'string', 'max:200'],
            'context.topic'    => ['nullable', 'string', 'max:200'],
        ]);

        $context = $data['context'] ?? [];
        $contextStr = '';
        if (!empty($context['course'])) $contextStr .= "Course: {$context['course']}. ";
        if (!empty($context['lesson'])) $contextStr .= "Lesson: {$context['lesson']}. ";
        if (!empty($context['topic']))  $contextStr .= "Topic: {$context['topic']}.";

        $systemPrompt = "You are SAFCO AI Tutor, an expert educational assistant for SAFCO FINTECH LMS. "
            . "You specialize in Microsoft Excel, Power BI, Accounting, Finance, IFRS, ERP systems, and financial modelling. "
            . "Be concise, practical, and encouraging. Use examples relevant to East African business context when helpful."
            . ($contextStr ? " Current learning context: {$contextStr}" : '');

        try {
            $provider = $this->tutorProvider();
            $reply = $this->callTutor($provider, $data['messages'], $systemPrompt);
            return $this->success(['reply' => $reply]);
        } catch (AiException $e) {
            Log::error('ai.tutor.failed', ['error' => $e->getMessage()]);
            return $this->error('AI tutor is temporarily unavailable. Please try again.', 503);
        }
    }

    /** POST /api/v1/ai/grade */
    public function grade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_title'        => ['required', 'string', 'max:200'],
            'assignment_instructions' => ['nullable', 'string', 'max:5000'],
            'student_answer'          => ['required', 'string', 'min:10', 'max:20000'],
            'max_points'              => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $maxPts = (int) $data['max_points'];
        $prompt = <<<PROMPT
You are an expert academic grader for SAFCO FINTECH LMS, a professional training platform.

Assignment: {$data['assignment_title']}
Instructions: {$data['assignment_instructions']}
Maximum points: {$maxPts}

Student's answer:
---
{$data['student_answer']}
---

Grade this submission fairly and return ONLY valid JSON (no markdown):
{
  "suggested_grade": <integer 0-{$maxPts}>,
  "percentage": <float 0-100>,
  "feedback": "<2-3 sentence overall feedback>",
  "strengths": ["<strength 1>", "<strength 2>"],
  "improvements": ["<improvement 1>", "<improvement 2>"]
}
PROMPT;

        try {
            $provider = $this->questionProvider();
            $raw = $this->callProvider($provider, $prompt, true);
            $parsed = json_decode($raw, true);

            if (!isset($parsed['suggested_grade'])) {
                if (preg_match('/\{.*"suggested_grade".*\}/s', $raw, $m)) {
                    $parsed = json_decode($m[0], true);
                }
            }

            if (!isset($parsed['suggested_grade'])) {
                return $this->error('AI grading returned unexpected format.', 422);
            }

            // Clamp grade to valid range
            $parsed['suggested_grade'] = max(0, min($maxPts, (int) $parsed['suggested_grade']));
            $parsed['percentage'] = round(($parsed['suggested_grade'] / $maxPts) * 100, 2);

            return $this->success($parsed);
        } catch (AiException $e) {
            Log::error('ai.grade.failed', ['error' => $e->getMessage()]);
            return $this->error('AI grading is temporarily unavailable.', 503);
        }
    }

    // ── Internal helpers ───────────────────────────────────────────────────────

    private function callProvider(GeminiClient|GroqClient|OpenRouterClient $provider, string $prompt, bool $jsonMode = false): string
    {
        if ($provider instanceof GeminiClient) {
            return $provider->generate($prompt);
        }
        $messages = [['role' => 'user', 'content' => $prompt]];
        if ($provider instanceof GroqClient) {
            return $provider->chat($messages, 0.3, 4096, $jsonMode);
        }
        // OpenRouterClient — no jsonMode param
        return $provider->chat($messages, 0.3, 4096);
    }

    private function callTutor(GeminiClient|GroqClient|OpenRouterClient $provider, array $messages, string $system): string
    {
        if ($provider instanceof GeminiClient) {
            $history = [];
            foreach (array_slice($messages, 0, -1) as $m) {
                $history[] = ['role' => $m['role'] === 'user' ? 'user' : 'model', 'text' => $m['content']];
            }
            $last = end($messages);
            return $provider->generate($last['content'], $history, $system);
        }

        // Groq / OpenRouter — OpenAI-compatible chat format
        $openaiMessages = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $openaiMessages[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        return $provider->chat($openaiMessages, 0.6, 1024);
    }

    private function extractFileText(\Illuminate\Http\UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if ($ext === 'txt') {
            return file_get_contents($path) ?: '';
        }
        if ($ext === 'pdf') {
            // Best-effort PDF text extraction
            if (function_exists('exec')) {
                $text = '';
                exec("pdftotext " . escapeshellarg($path) . " -", $lines, $code);
                if ($code === 0) return implode("\n", $lines);
            }
            return "(PDF file — unable to extract text server-side. Using filename as context: {$file->getClientOriginalName()})";
        }
        return "(File: {$file->getClientOriginalName()})";
    }
}
