#!/bin/bash

# Sunny AI Chat Server Startup Script
# This script initializes and starts the Sunny WebSocket server

echo "🌞 Starting Sunny AI Chat Server..."

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "❌ Node.js is not installed. Please install Node.js 16+ first."
    exit 1
fi

# Check Node.js version
NODE_VERSION=$(node -v | cut -d'v' -f2 | cut -d'.' -f1)
if [ "$NODE_VERSION" -lt 16 ]; then
    echo "❌ Node.js version 16+ is required. Current version: $(node -v)"
    exit 1
fi

# Navigate to server directory
cd "$(dirname "$0")"

# Create logs directory if it doesn't exist
mkdir -p logs

# Check if package.json exists
if [ ! -f "package.json" ]; then
    echo "❌ package.json not found. Make sure you're in the correct directory."
    exit 1
fi

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
    echo "📦 Installing dependencies..."
    npm install
    if [ $? -ne 0 ]; then
        echo "❌ Failed to install dependencies."
        exit 1
    fi
fi

# Check if .env file exists, if not copy from example
if [ ! -f ".env" ]; then
    if [ -f "config.env.example" ]; then
        echo "⚠️  No .env file found. Copying from config.env.example..."
        cp config.env.example .env
        echo "📝 Please edit .env file with your configuration before running again."
        exit 1
    else
        echo "⚠️  No .env file found. Please create one with your configuration."
        exit 1
    fi
fi

# Check if Ollama is accessible
echo "🔍 Checking Ollama connection..."
OLLAMA_URL=$(grep OLLAMA_URL .env | cut -d'=' -f2)
if [ -z "$OLLAMA_URL" ]; then
    OLLAMA_URL="https://ai.solterrasol.com:11434"
fi

# Test Ollama connection
if curl -s --max-time 10 "$OLLAMA_URL/api/tags" > /dev/null 2>&1; then
    echo "✅ Ollama is accessible at $OLLAMA_URL"
else
    echo "⚠️  Warning: Cannot reach Ollama at $OLLAMA_URL"
    echo "   The server will start but AI features may not work."
fi

# Load environment variables
set -a
source .env
set +a

# Set default port if not specified
if [ -z "$SUNNY_PORT" ]; then
    export SUNNY_PORT=3001
fi

echo "🚀 Starting Sunny server on port $SUNNY_PORT..."

# Start the server
if [ "$NODE_ENV" = "development" ]; then
    echo "🔧 Running in development mode with nodemon..."
    npm run dev
else
    echo "🏭 Running in production mode..."
    npm start
fi 