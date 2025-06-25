# Sunny AI Assistant 🌞

Sunny is an intelligent logistics assistant for the Solterra Solutions portal that provides real-time chat support with AI-powered responses, integrated with your logistics data.

## Features

- 🤖 **AI-Powered Chat**: Uses OpenAI O3 model for high-quality intelligent responses
- 🔒 **Role-Based Security**: Respects portal user roles and account access
- 📊 **Data Integration**: Access to projects, deliveries, warehouses, and more
- ⚡ **Real-time Streaming**: Server-sent events for instant responses
- 🎨 **Portal Integration**: Seamlessly integrated with existing portal design
- 📱 **Mobile Responsive**: Works perfectly on all device sizes
- 💰 **Cost-Effective**: Pay-per-use pricing with built-in cost tracking

## Architecture

### Components

1. **Frontend UI** (`components/`)
   - `sunny-chat.php` - Main chat component
   - `sunny-chat.css` - Styling and animations
   - `sunny-chat.js` - Client-side JavaScript with EventSource

2. **Backend API** (`api/`)
   - `query-executor.php` - Secure database query handler
   - `sunny-tools.php` - Pre-built logistics functions
   - `openai-client.php` - OpenAI API integration
   - `chat-stream.php` - Server-sent events streaming
   - `test-connection.php` - API connectivity testing

3. **Configuration** (`config/`)
   - `sunny-config.php` - OpenAI and application settings

4. **AI Configuration**
   - `sunny_system_prompt.md` - AI behavior and tool definitions

## Prerequisites

- **PHP 7.4+** with MySQLi and cURL extensions
- **OpenAI API Key** (O3 model access)
- **MySQL/MariaDB** database access
- **Web server** (Apache/Nginx) with standard PHP support

## Installation

### 1. Upload Files

Upload the entire `ai-assistant` folder to your web server under your logistics portal directory.

### 2. API Key Configuration

The OpenAI API key is automatically configured through your existing `env.php` system:

```php
// Already added to your env.php
putenv('OPENAI_API_KEY=your_api_key_here');
```

### 3. Verify Configuration

Test the setup by visiting:
```
https://yourdomain.com/Solterra-Logistics-Portal/ai-assistant/api/test-connection.php
```

### 4. Integration

The PHP components integrate automatically with your portal. Sunny will appear on all portal pages for logged-in users.

## Deployment

### Production Deployment

1. **File Upload**: Upload the `ai-assistant` folder to your web server

2. **Permissions**: Ensure proper file permissions:
   ```bash
   # Set directory permissions
   chmod 755 ai-assistant/
   chmod 644 ai-assistant/api/*.php
   chmod 644 ai-assistant/config/*.php
   chmod 644 ai-assistant/components/*
   ```

3. **Logs Directory**: Create a logs directory for cost tracking:
   ```bash
   mkdir ai-assistant/logs
   chmod 755 ai-assistant/logs
   ```

4. **SSL/HTTPS**: Standard HTTPS configuration through your web server

### No Additional Infrastructure Required

- ✅ **No Node.js server** needed
- ✅ **No additional ports** to open  
- ✅ **No process management** required
- ✅ **Standard PHP hosting** works perfectly

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
# Test API connectivity
curl https://yourdomain.com/Solterra-Logistics-Portal/ai-assistant/api/test-connection.php

# Check OpenAI API status
# (API key and connectivity test built into the test endpoint)
```

### Logs

Application logs are stored in `ai-assistant/logs/`:
- `sunny.log` - Chat interactions and errors
- `sunny-costs.log` - Cost tracking and usage metrics

```bash
# Monitor chat logs
tail -f ai-assistant/logs/sunny.log

# Monitor cost usage
tail -f ai-assistant/logs/sunny-costs.log
```

### Cost Monitoring

Built-in cost tracking helps you monitor OpenAI API usage:
- Daily cost limits per user (configurable)
- Real-time usage tracking
- Cost breakdowns by model and tokens

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
   - Ensure CSS and JS files are loading correctly

2. **Connection errors**:
   - Test API connectivity using test-connection.php
   - Verify OpenAI API key is correctly set in env.php
   - Check server error logs for cURL or API issues

3. **No AI responses**:
   - Verify OpenAI API key has correct permissions
   - Check if daily cost limits have been reached
   - Review cost logs for API errors

4. **Database errors**:
   - Verify database credentials in main config
   - Check user permissions for read access
   - Verify account_id relationships

### Debug Mode

Enable debug logging in the configuration:

```php
// In sunny-config.php
'logging' => [
    'enable_chat_logs' => true,
    'enable_error_logs' => true,
    'enable_cost_logs' => true
]
```

## Customization

### Adding New Tools

1. Add function to `api/sunny-tools.php`
2. Update tool parsing in `api/openai-client.php`
3. Test new functionality with the test connection endpoint

### Modifying System Prompt

Edit `ai-assistant/sunny_system_prompt.md` to update Sunny's behavior and personality.

### Styling Changes

Modify `components/sunny-chat.css` to match your portal's theme.

### Cost Management

Adjust cost limits and tracking in `config/sunny-config.php`:
- Daily cost limits per user
- Rate limiting settings  
- Model selection (O3, GPT-4o, GPT-4o-mini)

## Security Considerations

- Sunny only executes read-only database queries
- All queries are filtered by user's account access
- OpenAI API key secured through env.php system
- Session-based authentication required
- Built-in rate limiting and cost controls
- No sensitive data exposed to AI model

## Support

For issues or questions:

1. Check the application logs first (`ai-assistant/logs/`)
2. Test API connectivity using test-connection.php
3. Verify all prerequisites are met
4. Review cost logs for usage patterns
5. Contact Solterra Solutions support if needed

## Version Information

- **Version**: 2.0.0 (OpenAI Migration)
- **Compatible with**: Solterra Logistics Portal
- **AI Model**: OpenAI O3 (with GPT-4o fallback options)
- **PHP**: 7.4+ required
- **Dependencies**: cURL extension, existing portal authentication 