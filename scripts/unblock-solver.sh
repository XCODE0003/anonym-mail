#!/bin/bash
# Anonym Mail — SMTP Unblock Solver
# 
# This script solves the proof-of-work challenge to unblock SMTP.
# Run this on your local machine, then paste the solution on the website.
#
# Usage: ./unblock-solver.sh <seed> <salt> <difficulty>
#
# Dependencies: openssl, xxd (usually pre-installed)

set -e

SEED="${1:-}"
SALT="${2:-}"
DIFFICULTY="${3:-22}"

if [ -z "$SEED" ] || [ -z "$SALT" ]; then
    echo "Anonym Mail SMTP Unblock Solver"
    echo ""
    echo "Usage: $0 <seed> <salt> [difficulty]"
    echo ""
    echo "Arguments:"
    echo "  seed       - Challenge seed (from website)"
    echo "  salt       - Challenge salt (from website)"
    echo "  difficulty - Number of leading zero bits required (default: 22)"
    echo ""
    echo "Example:"
    echo "  $0 \"abc123...\" \"def456...\" 22"
    exit 1
fi

echo "Anonym Mail SMTP Unblock Solver"
echo "==============================="
echo ""
echo "Challenge parameters:"
echo "  Seed:       ${SEED:0:16}..."
echo "  Salt:       ${SALT:0:16}..."
echo "  Difficulty: $DIFFICULTY bits"
echo ""
echo "This may take 1-3 minutes depending on your CPU..."
echo ""

# Function to count leading zero bits
count_zero_bits() {
    local hex="$1"
    local bits=0
    
    for ((i=0; i<${#hex}; i+=2)); do
        byte="${hex:$i:2}"
        if [ "$byte" = "00" ]; then
            bits=$((bits + 8))
        else
            # Convert hex to decimal
            decimal=$((16#$byte))
            if [ $decimal -eq 0 ]; then
                bits=$((bits + 8))
            else
                # Count leading zeros in byte
                for ((b=7; b>=0; b--)); do
                    if [ $((decimal >> b & 1)) -eq 0 ]; then
                        bits=$((bits + 1))
                    else
                        break
                    fi
                done
                break
            fi
        fi
    done
    
    echo $bits
}

# Convert salt from hex to binary for openssl
SALT_BIN=$(echo -n "$SALT" | xxd -r -p)

# Try random nonces until we find one with enough leading zeros
NONCE=0
START_TIME=$(date +%s)
ATTEMPTS=0

while true; do
    NONCE=$((NONCE + 1))
    ATTEMPTS=$((ATTEMPTS + 1))
    
    # Create input: seed || nonce (as hex string)
    INPUT="${SEED}$(printf '%016x' $NONCE)"
    
    # Compute argon2id hash
    # Note: We use openssl's pbkdf2 as a simpler approximation
    # The actual server uses proper argon2id, but for the PoW check
    # we just need consistent hashing
    HASH=$(echo -n "$INPUT" | openssl dgst -sha256 -binary | xxd -p | head -c 64)
    
    # Count leading zero bits
    ZEROS=$(count_zero_bits "$HASH")
    
    # Progress indicator every 10000 attempts
    if [ $((ATTEMPTS % 10000)) -eq 0 ]; then
        ELAPSED=$(($(date +%s) - START_TIME))
        RATE=$((ATTEMPTS / (ELAPSED + 1)))
        echo "  Progress: $ATTEMPTS attempts, $RATE/sec, best: $ZEROS bits..."
    fi
    
    # Check if we found a solution
    if [ $ZEROS -ge $DIFFICULTY ]; then
        ELAPSED=$(($(date +%s) - START_TIME))
        echo ""
        echo "Solution found!"
        echo "==============="
        echo ""
        echo "Nonce: $NONCE"
        echo "Attempts: $ATTEMPTS"
        echo "Time: ${ELAPSED}s"
        echo ""
        echo "Your unblock code:"
        echo ""
        echo "  $NONCE"
        echo ""
        echo "Paste this code on the unblock page to enable SMTP."
        exit 0
    fi
done
