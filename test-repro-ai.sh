#!/bin/bash

# Robbie Testing Script
# Usage: ./test-repro-ai.sh [your-auth-token]

API_URL="${API_URL:-http://localhost:8000/api}"
TOKEN="${1:-}"

if [ -z "$TOKEN" ]; then
    echo "❌ Error: Auth token required"
    echo "Usage: ./test-repro-ai.sh YOUR_AUTH_TOKEN"
    echo "Or set TOKEN environment variable"
    exit 1
fi

echo "🧪 Testing Robbie Rule-Based Chat"
echo "===================================="
echo ""

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test 1: Start conversation
echo -e "${BLUE}Test 1: Starting conversation (Book a shoot)${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"message": "I want to book a shoot"}')

SESSION_ID=$(echo $RESPONSE | jq -r '.sessionId // empty')
if [ -z "$SESSION_ID" ]; then
    echo "❌ Failed to create session"
    echo "$RESPONSE" | jq
    exit 1
fi

echo -e "${GREEN}✓ Session created: $SESSION_ID${NC}"
echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Test 2: Provide property
echo -e "${BLUE}Test 2: Providing property address${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"123 Main Street, San Francisco, CA 94102\", \"context\": {\"propertyAddress\": \"123 Main Street\", \"propertyCity\": \"San Francisco\", \"propertyState\": \"CA\", \"propertyZip\": \"94102\"}}")

echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Test 3: Provide date
echo -e "${BLUE}Test 3: Providing date${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Tomorrow\"}")

echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Test 4: Provide time
echo -e "${BLUE}Test 4: Providing time${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Morning\"}")

echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Test 5: Provide services
echo -e "${BLUE}Test 5: Providing services${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Photos only\"}")

echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Test 6: Confirm
echo -e "${BLUE}Test 6: Confirming booking${NC}"
RESPONSE=$(curl -s -X POST "$API_URL/ai/chat" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{\"sessionId\": \"$SESSION_ID\", \"message\": \"Yes, book it\"}")

echo "$RESPONSE" | jq -r '.messages[-1].content'
echo ""

# Check if shoot was created
SHOOT_ID=$(echo $RESPONSE | jq -r '.meta.actions[0].shoot_id // empty')
if [ ! -z "$SHOOT_ID" ]; then
    echo -e "${GREEN}✓ Shoot created with ID: $SHOOT_ID${NC}"
else
    echo -e "${YELLOW}⚠ No shoot_id in actions (might be expected if services are missing)${NC}"
fi

echo ""
echo -e "${GREEN}✅ All tests completed!${NC}"
echo "Session ID: $SESSION_ID"
echo ""
echo "View session messages:"
echo "curl -H \"Authorization: Bearer $TOKEN\" $API_URL/ai/sessions/$SESSION_ID"

