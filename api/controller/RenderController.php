<?php

namespace api\controller;

use api\dtos\StackRenderInput;
use api\dtos\StackRenderResponse;
use api\util\StackPlotReplacer;
use api\util\StackQuestionLoader;
use api\util\StackSeedHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RenderController
{
    /**
     * @throws \stack_exception
     * @throws \Exception
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        //TODO: Validate
        $data = $request->getParsedBody();

        //Load Functions emulating Moodle
        require_once(__DIR__ . '/../emulation/MoodleEmulation.php');

        $question = StackQuestionLoader::loadXML($data["questionDefinition"]);

        StackSeedHelper::initializeSeed($question, $data["seed"]);

        //handle Pluginfiles
        $filePrefix = uniqid();
        StackPlotReplacer::persistPluginfiles($question, $filePrefix);

        $question->initialise_question_from_seed();

        $question->castextprocessor = new \castext2_qa_processor(new \stack_outofcontext_process());

        if (!empty($question->runtimeerrors)) {
            // The question has not been instantiated successfully, at this level it is likely
            // a failure at compilation and that means invalid teacher code.
            throw new \stack_exception(implode("\n", array_keys($question->runtimeerrors)));
        }

        $translate = new \stack_multilang();
        // This is a hack, that restores the filter regex to the exact one used in moodle.
        // The modifications done by the stack team prevent the filter funcitonality from working correctly.
        $translate->search = '/(<span(\s+lang="[a-zA-Z0-9_-]+"|\s+class="multilang"){2}\s*>.*?<\/span>)(\s*<span(\s+lang="[a-zA-Z0-9_-]+"|\s+class="multilang"){2}\s*>.*?<\/span>)+/is';
        $language = current_language();

        $renderResponse = new StackRenderResponse();
        $plots = [];

        $renderResponse->QuestionRender = $translate->filter(
            \stack_maths::process_display_castext(
                $question->questiontextinstantiated->get_rendered(
                    $question->castextprocessor
                )
            ),
            $language
        );

        array_push($plots, ...StackPlotReplacer::replace_plots($renderResponse->QuestionRender, $filePrefix));

        $renderResponse->QuestionSampleSolutionText = $translate->filter(
            $question->get_generalfeedback_castext()->get_rendered($question->castextprocessor),
            $language
        );

        array_push($plots, ...StackPlotReplacer::replace_plots($renderResponse->QuestionSampleSolutionText, $filePrefix));

        $inputs = array();
        foreach ($question->inputs as $name => $input) {
            $apiInput = new StackRenderInput();

            $apiInput->SampleSolution = $input->getApiSolution($question->get_ta_for_input($name));
            $apiInput->SampleSolutionRender = $input->getApiSolutionRender($question->get_ta_render_for_input($name));

            $apiInput->ValidationType = $input->get_parameter('showValidation', 1);
            $apiInput->Configuration = $input->renderApiData($question->get_ta_for_input($name));

            if(array_key_exists('options', $apiInput->Configuration)) {
                foreach ($apiInput->Configuration['options'] as &$option) {
                    array_push($plots, ...StackPlotReplacer::replace_plots($option, $filePrefix));
                }
            }

            $inputs[$name] = $apiInput;
        }

        // Necessary, as php will otherwise encode this as an empty array, instead of an empty object
        $renderResponse->QuestionInputs = (object) $inputs;

        $renderResponse->QuestionAssets = $plots;

        $renderResponse->QuestionSeed = $question->seed;
        $renderResponse->QuestionVariants = $question->deployedseeds;

        $response->getBody()->write(json_encode($renderResponse));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
