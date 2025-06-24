# Sunny AI Assistant 🌞

Sunny is an intelligent logistics assistant for the Solterra Solutions portal that provides real-time chat support with AI-powered responses, integrated with your logistics data.

## Features

- 🤖 **AI-Powered Chat**: Uses Ollama with Gemma 2B model for intelligent responses
- 🔒 **Role-Based Security**: Respects portal user roles and account access
- 📊 **Data Integration**: Access to projects, deliveries, warehouses, and more
- ⚡ **Real-time Streaming**: WebSocket-based streaming for instant responses
- 🎨 **Portal Integration**: Seamlessly integrated with existing portal design
- 📱 **Mobile Responsive**: Works perfectly on all device sizes

## Architecture

### Components

1. **Frontend UI** (`components/`)
   - `sunny-chat.php` - Main chat component
   - `sunny-chat.css` - Styling and animations
   - `sunny-chat.js` - Client-side JavaScript with Socket.IO

2. **Backend API** (`api/`)
   - `query-executor.php` - Secure database query handler
   - `sunny-tools.php` - Pre-built logistics functions
   - `ollama-client.php` - Ollama API integration

3. **WebSocket Server** (`server/`)
   - `server.js` - Node.js Socket.IO server
   - Handles real-time communication and streaming

4. **AI Configuration**
   - `sunny_system_prompt.md` - AI behavior and tool definitions

## Prerequisites

- **PHP 7.4+** with MySQLi extension
- **Node.js 16+** and npm
- **Ollama server** running with Gemma 2B model
- **MySQL/MariaDB** database access
- **Web server** (Apache/Nginx) with WebSocket support

## Installation

### 1. Backend Setup

The PHP components are already integrated into your portal structure. Sunny will automatically appear on all portal pages for logged-in users.

### 2. Node.js Server Setup

```bash
# Navigate to the server directory
cd Solterra-Logistics-Portal/ai-assistant/server

# Install dependencies
npm install

# Copy and configure environment
cp config.env.example .env
nano .env  # Edit with your settings

# Start the server
./start-sunny.sh
```

### 3. Environment Configuration

Edit `.env` file in the server directory:

```bash
# Server Settings
SUNNY_PORT=3001
NODE_ENV=production

# Ollama Configuration
OLLAMA_URL=https://ai.solterrasol.com:11434
OLLAMA_MODEL=gemma:2b

# Database Configuration
DB_HOST=localhost
DB_USER=SolterraSolutions
DB_PASSWORD=CompanyAdmin!
DB_NAME=solterra_portal
```

### 4. Ollama Model Setup

Ensure your Ollama server has the Gemma 2B model:

```bash
# On your Ollama server
ollama pull gemma:2b
ollama list  # Verify model is available
```

## Deployment

### Production Deployment

1. **Reverse Proxy Setup** (Nginx example):

```nginx
# Add to your nginx config
location /socket.io/ {
    proxy_pass http://localhost:3001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

2. **Process Management** (PM2 example):

```bash
# Install PM2
npm install -g pm2

# Start Sunny with PM2
cd Solterra-Logistics-Portal/ai-assistant/server
pm2 start server.js --name "sunny-chat"
pm2 startup  # Configure auto-start
pm2 save
```

3. **SSL/HTTPS Configuration**:
   - Ensure your web server supports WebSocket over HTTPS
   - Update CORS origins in the Node.js server configuration

### Firewall Configuration

```bash
# Allow internal communication to Node.js server
ufw allow 3001/tcp

# Or restrict to localhost only
ufw allow from 127.0.0.1 to any port 3001
```

## Configuration

### User Roles and Permissions

Sunny respects your existing portal role system:

- **global_admin**: Access to all data across all accounts
- **admin**: Access to their account's data only
- **user**: Limited access to projects and deliveries
- **DDPm**: Read-only access to basic logistics data

### Available Tools

Sunny has access to these pre-built functions:

1. **getProjectSummary()** - Project status and module counts
2. **getDeliveryStatus()** - Delivery tracking and status
3. **getWarehouseInventory()** - Warehouse and inventory data
4. **getModuleMovements()** - Recent module location changes
5. **getProjectCostAnalysis()** - Cost breakdowns and analysis
6. **getDeliveryPerformance()** - Performance metrics
7. **getKPIDashboard()** - Key performance indicators
8. **searchLogistics()** - Cross-table search functionality

### Security Features

- **Read-only database access** - Only SELECT and SHOW statements allowed
- **Role-based filtering** - Automatic account_id filtering for non-admin users
- **SQL injection protection** - Prepared statements and input validation
- **Session validation** - Integration with existing PHP session system
- **Rate limiting** - Prevents abuse and spam

## Monitoring

### Health Checks

```bash
# Check server status
curl http://localhost:3001/health

# Check Ollama connectivity
curl https://ai.solterrasol.com:11434/api/tags
```

### Logs

Server logs are stored in `server/logs/`:
- `combined.log` - All log entries
- `error.log` - Error messages only

```bash
# Monitor logs
tail -f server/logs/combined.log
```

### Performance Monitoring

```bash
# With PM2
pm2 status
pm2 monit

# Or check Node.js process
ps aux | grep node
netstat -tulpn | grep 3001
```

## Usage

### For End Users

1. **Starting a Chat**: Click the Sunny button (☀️) in the bottom-right corner
2. **Quick Actions**: Use the pre-defined buttons for common queries
3. **Natural Language**: Ask questions in plain English
4. **Real-time Responses**: Watch responses stream in real-time

### Example Queries

- "Show me my recent deliveries"
- "What's the status of project ABC123?"
- "How many modules are in storage?"
- "Find delivery with tracking number 1234567890"
- "Show me the warehouse inventory summary"

## Troubleshooting

### Common Issues

1. **Chat not appearing**:
   - Verify user is logged into portal
   - Check browser console for JavaScript errors
   - Ensure CSS file is loading correctly

2. **Connection errors**:
   - Verify Node.js server is running (`ps aux | grep node`)
   - Check firewall settings
   - Verify WebSocket support in web server config

3. **No AI responses**:
   - Check Ollama server accessibility
   - Verify model is loaded (`ollama list`)
   - Check Node.js server logs

4. **Database errors**:
   - Verify database credentials in .env
   - Check user permissions for read access
   - Verify account_id relationships

### Debug Mode

Enable debug logging:

```bash
# Set in .env file
LOG_LEVEL=debug
NODE_ENV=development

# Restart server
pm2 restart sunny-chat
```

## Customization

### Adding New Tools

1. Add function to `api/sunny-tools.php`
2. Update tool parsing in `server/server.js`
3. Add pattern matching in `api/ollama-client.php`

### Modifying System Prompt

Edit `ai-assistant/sunny_system_prompt.md` or update the default prompt in `api/ollama-client.php`.

### Styling Changes

Modify `components/sunny-chat.css` to match your portal's theme.

## Security Considerations

- Sunny only executes read-only database queries
- All queries are filtered by user's account access
- No sensitive data (passwords, API keys) is exposed
- WebSocket connections are authenticated
- Rate limiting prevents abuse

## Support

For issues or questions:

1. Check the logs first (`server/logs/`)
2. Verify all prerequisites are met
3. Test each component individually
4. Contact Solterra Solutions support if needed

## Version Information

- **Version**: 1.0.0
- **Compatible with**: Solterra Logistics Portal
- **Node.js**: 16+ required
- **PHP**: 7.4+ required
- **Ollama**: Compatible with Gemma 2B model 