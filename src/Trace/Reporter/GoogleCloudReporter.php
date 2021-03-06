<?php
/**
 * Copyright 2017 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Trace\Reporter;

use Google\Cloud\Core\Batch\BatchRunner;
use Google\Cloud\Core\Batch\BatchTrait;
use Google\Cloud\Trace\TraceClient;
use Google\Cloud\Trace\TraceSpan;
use OpenCensus\Trace\Tracer\TracerInterface;
use OpenCensus\Trace\TraceSpan as OpenCensusTraceSpan;

/**
 * This implementation of the ReporterInterface use the BatchRunner to provide
 * reporting of Traces and their TraceSpans to Google Cloud Stackdriver Trace.
 *
 * Example:
 * ```
 * use OpenCensus\Trace\RequestTracer;
 * use OpenCensus\Trace\Reporter\GoogleCloudReporter;
 *
 * $reporter = new GoogleCloudReporter([
 *   'clientConfig' => [
 *      'projectId' => 'my-project'
 *   ]
 * ]);
 * RequestTracer::start($reporter);
 * ```
 *
 * The above configuration will synchronously report the traces to Google Cloud
 * Stackdriver Trace. You can enable an experimental asynchronous reporting
 * mechanism using (BatchDaemon)[https://github.com/GoogleCloudPlatform/google-cloud-php/tree/master/src/Core/Batch].
 *
 * Example:
 * ```
 * use OpenCensus\Trace\RequestTracer;
 * use OpenCensus\Trace\Reporter\GoogleCloudReporter;
 *
 * $reporter = new GoogleCloudReporter([
 *   'async' => true,
 *   'clientConfig' => [
 *      'projectId' => 'my-project'
 *   ]
 * ]);
 * RequestTracer::start($reporter);
 * ```
 *
 * Note that to use the `async` option, you will also need to set the
 * `IS_BATCH_DAEMON_RUNNING` environment variable to `true`.
 *
 * @experimental The experimental flag means that while we believe this method
 *      or class is ready for use, it may change before release in backwards-
 *      incompatible ways. Please use with caution, and test thoroughly when
 *      upgrading.
 */
class GoogleCloudReporter implements ReporterInterface
{
    const VERSION = '0.1.0';

    // These are Stackdriver Trace's common labels
    const AGENT = '/agent';
    const COMPONENT = '/component';
    const ERROR_MESSAGE = '/error/message';
    const ERROR_NAME = '/error/name';
    const HTTP_CLIENT_CITY = '/http/client_city';
    const HTTP_CLIENT_COUNTRY = '/http/client_country';
    const HTTP_CLIENT_PROTOCOL = '/http/client_protocol';
    const HTTP_CLIENT_REGION = '/http/client_region';
    const HTTP_HOST = '/http/host';
    const HTTP_METHOD = '/http/method';
    const HTTP_REDIRECTED_URL = '/http/redirected_url';
    const HTTP_STATUS_CODE = '/http/status_code';
    const HTTP_URL = '/http/url';
    const HTTP_USER_AGENT = '/http/user_agent';
    const PID = '/pid';
    const STACKTRACE = '/stacktrace';
    const TID = '/tid';

    const GAE_APPLICATION_ERROR = 'g.co/gae/application_error';
    const GAE_APP_MODULE = 'g.co/gae/app/module';
    const GAE_APP_MODULE_VERSION = 'g.co/gae/app/module_version';
    const GAE_APP_VERSION = 'g.co/gae/app/version';

    use BatchTrait;

    /**
     * @var TraceClient
     */
    private static $client;

    /**
     * @var bool
     */
    private $async;

    /**
     * Create a TraceReporter that utilizes background batching.
     *
     * @param array $options [optional] {
     *     Configuration options.
     *
     *     @type TraceClient $client A trace client used to instantiate traces
     *           to be delivered to the batch queue.
     *     @type bool $debugOutput Whether or not to output debug information.
     *           Please note debug output currently only applies in CLI based
     *           applications. **Defaults to** `false`.
     *     @type array $batchOptions A set of options for a BatchJob.
     *           {@see \Google\Cloud\Core\Batch\BatchJob::__construct()} for
     *           more details.
     *           **Defaults to** ['batchSize' => 1000,
     *                            'callPeriod' => 2.0,
     *                            'workerNum' => 2].
     *     @type array $clientConfig Configuration options for the Trace client
     *           used to handle processing of batch items.
     *           For valid options please see
     *           {@see \Google\Cloud\Trace\TraceClient::__construct()}.
     *     @type BatchRunner $batchRunner A BatchRunner object. Mainly used for
     *           the tests to inject a mock. **Defaults to** a newly created
     *           BatchRunner.
     *     @type string $identifier An identifier for the batch job.
     *           **Defaults to** `stackdriver-trace`.
     *     @type bool $async Whether we should try to use the batch runner.
     *           **Defaults to** `false`.
     * }
     */
    public function __construct(array $options = [])
    {
        $this->async = isset($options['async']) ? $options['async'] : false;
        $this->setCommonBatchProperties($options + [
            'identifier' => 'stackdriver-trace',
            'batchMethod' => 'insertBatch'
        ]);
        self::$client = isset($options['client'])
            ? $options['client']
            : new TraceClient($this->clientConfig);
    }

    /**
     * Report the provided Trace to a backend.
     *
     * @param  TracerInterface $tracer
     * @return bool
     */
    public function report(TracerInterface $tracer)
    {
        $this->processSpans($tracer);
        $spans = $this->convertSpans($tracer);

        if (empty($spans)) {
            return false;
        }

        // build a Trace object and assign TraceSpans
        $trace = self::$client->trace(
            $tracer->context()->traceId()
        );
        $trace->setSpans($spans);

        try {
            if ($this->async) {
                return $this->batchRunner->submitItem($this->identifier, $trace);
            } else {
                return self::$client->insert($trace);
            }
        } catch (\Exception $e) {
            error_log('Reporting the Trace data failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Perform any pre-conversion modification to the spans
     *
     * @param TracerInterface $tracer
     * @param array $headers [optional] Array of headers to read from instead of $_SERVER
     */
    public function processSpans(TracerInterface $tracer, $headers = null)
    {
        // detect common labels
        $this->addCommonLabels($tracer, $headers);
    }

    /**
     * Convert spans into Zipkin's expected JSON output format.
     *
     * @param  TracerInterface $tracer
     * @return array Representation of the collected trace spans ready for serialization
     */
    public function convertSpans(TracerInterface $tracer)
    {
        $spanKindMap = [
            OpenCensusTraceSpan::SPAN_KIND_CLIENT => TraceSpan::SPAN_KIND_RPC_CLIENT,
            OpenCensusTraceSpan::SPAN_KIND_SERVER => TraceSpan::SPAN_KIND_RPC_SERVER
        ];

        // transform OpenCensus TraceSpans to Google\Cloud\TraceSpans
        return array_map(function ($span) use ($spanKindMap) {
            $kind = array_key_exists($span->kind(), $spanKindMap)
                ? $spanKindMap[$span->kind()]
                :  TraceSpan::SPAN_KIND_UNSPECIFIED;
            $labels = $span->labels();
            $labels[self::STACKTRACE] = $this->formatBacktrace($span->backtrace());
            return new TraceSpan([
                'name' => $span->name(),
                'startTime' => $span->startTime(),
                'endTime' => $span->endTime(),
                'spanId' => $span->spanId(),
                'parentSpanId' => $span->parentSpanId(),
                'labels' => $labels,
                'kind' => $kind,
            ]);
            $span->info();
        }, $tracer->spans());
    }

    private function formatBacktrace($bt)
    {
        return json_encode([
            'stack_frame' => array_map([$this, 'mapStackframe'], $bt)
        ]);
    }

    private function mapStackframe($sf)
    {
        // file and line should always be set
        $data = [];
        if (isset($sf['line'])) {
            $data['line_number'] = $sf['line'];
        }
        if (isset($sf['file'])) {
            $data['file_name'] = $sf['file'];
        }
        if (isset($sf['function'])) {
            $data['method_name'] = $sf['function'];
        }
        if (isset($sf['class'])) {
            $data['class_name'] = $sf['class'];
        }
        return $data;
    }

    /**
     * Returns an array representation of a callback which will be used to write
     * batch items.
     *
     * @return array
     */
    protected function getCallback()
    {
        if (!isset(self::$client)) {
            self::$client = new TraceClient($this->clientConfig);
        }

        return [self::$client, $this->batchMethod];
    }

    private function addCommonLabels(&$tracer, $headers = null)
    {
        $headers = $headers ?: $_SERVER;

        // If a redirect, add the HTTP_REDIRECTED_URL label to the main span
        $responseCode = http_response_code();
        if ($responseCode == 301 || $responseCode == 302) {
            foreach (headers_list() as $header) {
                if (substr($header, 0, 9) == 'Location:') {
                    $tracer->addRootLabel(self::HTTP_REDIRECTED_URL, substr($header, 10));
                    break;
                }
            }
        }
        $tracer->addRootLabel(self::HTTP_STATUS_CODE, $responseCode);

        $labelMap = [
            self::HTTP_URL => ['REQUEST_URI'],
            self::HTTP_METHOD => ['REQUEST_METHOD'],
            self::HTTP_CLIENT_PROTOCOL => ['SERVER_PROTOCOL'],
            self::HTTP_USER_AGENT => ['HTTP_USER_AGENT'],
            self::HTTP_HOST => ['HTTP_HOST', 'SERVER_NAME'],
            self::GAE_APP_MODULE => ['GAE_SERVICE'],
            self::GAE_APP_MODULE_VERSION => ['GAE_VERSION'],
            self::HTTP_CLIENT_CITY => ['HTTP_X_APPENGINE_CITY'],
            self::HTTP_CLIENT_REGION => ['HTTP_X_APPENGINE_REGION'],
            self::HTTP_CLIENT_COUNTRY => ['HTTP_X_APPENGINE_COUNTRY']
        ];
        foreach ($labelMap as $labelKey => $headerKeys) {
            if ($val = $this->detectKey($headerKeys, $headers)) {
                $tracer->addRootLabel($labelKey, $val);
            }
        }
        $tracer->addRootLabel(self::PID, '' . getmypid());
        $tracer->addRootLabel(self::AGENT, 'opencensus ' . self::VERSION);
    }

    private function detectKey(array $keys, array $array)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                return $array[$key];
            }
        }
        return null;
    }
}
