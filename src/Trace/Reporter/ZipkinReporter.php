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

use OpenCensus\Trace\Tracer\TracerInterface;
use OpenCensus\Trace\TraceSpan;

/**
 * This implementation of the ReporterInterface appends a json
 * representation of the trace to a file.
 */
class ZipkinReporter implements ReporterInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $host;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $url;

    /**
     * Create a new ZipkinReporter
     *
     * @param string $name The name of this application
     * @param string $host The hostname of the Zipkin server
     * @param int $port The port of the Zipkin server
     * @param string $endpoint (optional) The path for the span reporting endpoint. **Defaults to** `/api/v1/spans`
     */
    public function __construct($name, $host, $port, $endpoint = '/api/v2/spans')
    {
        $this->name = $name;
        $this->host = $host;
        $this->port = $port;
        $this->url = "http://${host}:${port}${endpoint}";
    }

    /**
     * Report the provided Trace to a backend.
     *
     * @param  TracerInterface $tracer
     * @return bool
     */
    public function report(TracerInterface $tracer)
    {
        $spans = $this->convertSpans($tracer);

        if (empty($spans)) {
            return false;
        }

        try {
            $json = json_encode($spans);
            $contextOptions = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => $json
                ]
            ];

            $context = stream_context_create($contextOptions);
            file_get_contents($this->url, false, $context);
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Convert spans into Zipkin's expected JSON output format. See http://zipkin.io/zipkin-api/#/default/post_spans
     * for output format.
     *
     * @param TracerInterface $tracer
     * @param array $headers [optional] HTTP headers to parse. **Defaults to** $_SERVER
     * @return array Representation of the collected trace spans ready for serialization
     */
    public function convertSpans(TracerInterface $tracer, $headers = null)
    {
        $headers = $headers ?: $_SERVER;
        $spans = $tracer->spans();
        $rootSpan = $spans[0];
        $traceId = $tracer->context()->traceId();

        $kindMap = [
            TraceSpan::SPAN_KIND_CLIENT => 'CLIENT',
            TraceSpan::SPAN_KIND_SERVER => 'SERVER',
            TraceSpan::SPAN_KIND_PRODUCER => 'PRODUCER',
            TraceSpan::SPAN_KIND_CONSUMER => 'CONSUMER'
        ];

        // True is a request to store this span even if it overrides sampling policy.
        // This is true when the X-B3-Flags header has a value of 1.
        $isDebug = array_key_exists('HTTP_X_B3_FLAGS', $headers) && $headers['HTTP_X_B3_FLAGS'] == '1';

        // True if we are contributing to a span started by another tracer (ex on a different host).
        $isShared = $rootSpan && $rootSpan->parentSpanId() != null;

        $localEndpoint = [
            'serviceName' => $this->name,
            'ipv4' => $this->host,
            'port' => $this->port
        ];

        $zipkinSpans = [];
        foreach ($spans as $span) {
            $startTime = (int)((float) $span->startTime()->format('U.u') * 1000 * 1000);
            $endTime = (int)((float) $span->endTime()->format('U.u') * 1000 * 1000);
            $spanId = str_pad(dechex($span->spanId()), 16, '0', STR_PAD_LEFT);
            $parentSpanId = $span->parentSpanId()
                ? str_pad(dechex($span->parentSpanId()), 16, '0', STR_PAD_LEFT)
                : null;

            $zipkinSpan = [
                'traceId' => $traceId,
                'name' => $span->name(),
                'parentId' => $parentSpanId,
                'id' => $spanId,
                'timestamp' => $startTime,
                'duration' => $endTime - $startTime,
                'debug' => $isDebug,
                'shared' => $isShared,
                'localEndpoint' => $localEndpoint,
                'tags' => $span->labels()
            ];
            if (array_key_exists($span->kind(), $kindMap)) {
                $zipkinSpan['kind'] = $kindMap[$span->kind()];
            }

            $zipkinSpans[] = $zipkinSpan;
        }

        return $zipkinSpans;
    }
}
