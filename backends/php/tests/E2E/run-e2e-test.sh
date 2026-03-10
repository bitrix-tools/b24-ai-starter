#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "🚀 Running Telemetry E2E Test"
echo "================================"

# Step 1: Check if ClickHouse is available
echo ""
echo "📋 Step 1: Checking ClickHouse availability..."
if curl -s http://localhost:8123/ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ ClickHouse is running${NC}"
else
    echo -e "${RED}✗ ClickHouse is not available at localhost:8123${NC}"
    echo "Please start b24-ai-starter-otel infrastructure:"
    echo "  cd /path/to/b24-ai-starter-otel && make up"
    exit 1
fi

# Step 2: Check if OTel Collector is available
echo ""
echo "📋 Step 2: Checking OTel Collector availability..."
if curl -s http://localhost:4318/v1/traces > /dev/null 2>&1; then
    echo -e "${GREEN}✓ OTel Collector is running${NC}"
else
    echo -e "${RED}✗ OTel Collector is not available at localhost:4318${NC}"
    echo "Please start b24-ai-starter-otel infrastructure"
    exit 1
fi

# Generate unique test ID
TEST_ID="e2e_test_$(date +%s)"
echo ""
echo "📋 Step 3: Sending test event (ID: $TEST_ID)..."

# Step 3: Send test event through PHP
OUTPUT=$(COMPOSE_PROFILES=php-cli docker-compose run --rm --workdir /var/www \
    -e OTEL_EXPORTER_OTLP_ENDPOINT=http://host.docker.internal:4318 \
    -e OTEL_SERVICE_NAME=e2e-test-app \
    -e OTEL_SERVICE_VERSION=1.0.0 \
    -e OTEL_ENVIRONMENT=test \
    php-cli php -r "
require_once 'vendor/autoload.php';

use App\Service\Telemetry\RealTelemetryService;
use App\Service\Telemetry\Config\OtlpConfig;
use Psr\Log\AbstractLogger;

// Create a simple console logger to see initialization errors
class ConsoleLogger extends AbstractLogger {
    public function log(\$level, \$message, array \$context = []): void {
        echo \"[\$level] \$message\" . PHP_EOL;
        if (!empty(\$context)) {
            echo json_encode(\$context, JSON_PRETTY_PRINT) . PHP_EOL;
        }
    }
}

try {
    // Create OTLP configuration directly
    \$config = new OtlpConfig(
        'http://host.docker.internal:4318',
        'e2e-test-app',
        '1.0.0',
        'test'
    );
    
    // Create RealTelemetryService with logger to see errors
    \$logger = new ConsoleLogger();
    \$telemetry = new RealTelemetryService(\$config, \$logger);
    
    if (!\$telemetry->isEnabled()) {
        echo 'ERROR: Telemetry failed to initialize' . PHP_EOL;
        exit(1);
    }
    
    \$telemetry->trackEvent('${TEST_ID}', [
        'test_type' => 'e2e_integration',
        'source' => 'automated_test_script',
        'timestamp' => time(),
        'environment' => 'test',
    ]);
    
    // Explicitly shutdown telemetry to flush all spans
    \$telemetry->shutdown();
    
    echo 'Event sent successfully' . PHP_EOL;
    
} catch (\Exception \$e) {
    echo 'ERROR: ' . \$e->getMessage() . PHP_EOL;
    echo \$e->getTraceAsString() . PHP_EOL;
    exit(1);
}
" 2>&1)

EXIT_CODE=$?

# Show full output for debugging
echo "$OUTPUT"

if echo "$OUTPUT" | grep -q "Event sent successfully"; then
    echo -e "${GREEN}✓ Event sent successfully${NC}"
elif echo "$OUTPUT" | grep -q "ERROR:"; then
    echo -e "${RED}✗ Failed to send event${NC}"
    echo "$OUTPUT"
    exit 1
else
    echo -e "${RED}✗ Failed to send event${NC}"
    echo "$OUTPUT"
    exit 1
fi

# Step 4: Wait for event to be processed
# Step 4: Wait for event to be processed
# OTel Collector uses batch processor with 10s timeout
# So we need to wait at least 12 seconds for event to appear in ClickHouse
echo ""
echo "📋 Step 4: Waiting for event to be processed..."
echo "Note: OTel Collector batches events with 10s timeout"

MAX_ATTEMPTS=6
SLEEP_INTERVAL=2
RESULT=0

for i in $(seq 1 $MAX_ATTEMPTS); do
    echo -n "  Attempt $i/$MAX_ATTEMPTS (after ${i}×${SLEEP_INTERVAL}s)..."
    sleep $SLEEP_INTERVAL
    
    RESULT=$(docker exec clickhouse clickhouse-client --query "
    SELECT COUNT(*) 
    FROM telemetry.otel_traces 
    WHERE SpanName = '${TEST_ID}'
    " 2>/dev/null || echo "0")
    
    if [ "$RESULT" -ge 1 ]; then
        echo -e " ${GREEN}Found!${NC}"
        break
    else
        echo " Not yet"
    fi
done

# Step 5: Check if event exists in ClickHouse
echo ""
echo "📋 Step 5: Checking ClickHouse for event..."

echo "Found $RESULT event(s) in ClickHouse"

if [ "$RESULT" -ge 1 ]; then
    echo ""
    echo -e "${GREEN}================================${NC}"
    echo -e "${GREEN}✓ E2E Test PASSED${NC}"
    echo -e "${GREEN}================================${NC}"
    echo ""
    echo "Event details:"
    docker exec clickhouse clickhouse-client --query "
    SELECT 
        Timestamp,
        TraceId,
        SpanName,
        ServiceName,
        SpanAttributes
    FROM telemetry.otel_traces
    WHERE SpanName = '${TEST_ID}'
    FORMAT Vertical
    " 2>/dev/null
    exit 0
else
    echo ""
    echo -e "${RED}================================${NC}"
    echo -e "${RED}✗ E2E Test FAILED${NC}"
    echo -e "${RED}================================${NC}"
    echo ""
    echo "Event was not found in ClickHouse"
    echo ""
    echo "Troubleshooting:"
    echo "1. Check OTel Collector logs:"
    echo "   docker logs otel-collector --tail 50"
    echo "2. Check PHP logs:"
    echo "   tail -f backends/php/var/log/*.log"
    echo "3. Verify ClickHouse tables:"
    echo "   docker exec clickhouse clickhouse-client --query 'SHOW TABLES FROM telemetry'"
    exit 1
fi
