# Sunny AI Assistant - PHP Deployment Guide

## Overview
Sunny has been converted to run entirely on PHP using Server-Sent Events (SSE) for real-time streaming, making it compatible with GoDaddy shared hosting.

## Files to Upload

Upload the entire `ai-assistant` directory to your GoDaddy hosting at:
```
/home/dbos1p4y0di2/public_html/Solterra-Logistics-Portal/ai-assistant/
```

### Directory Structure:
```
ai-assistant/
├── api/
│   ├── chat-stream.php          # Main streaming endpoint
│   ├── test-connection.php      # Connection test
│   ├── query-executor.php       # Database security layer
│   ├── sunny-tools.php          # Pre-built database functions
│   └── ollama-client.php        # AI communication (if needed)
├── components/
│   ├── sunny-chat.php           # UI component
│   ├── sunny-chat.css           # Styling
│   └── sunny-chat.js            # Frontend JavaScript
├── config/
│   └── sunny-config.php         # Configuration settings
└── sunny_system_prompt.md       # AI system instructions
```

## Configuration

### 1. Ollama Service Setup
The system is configured to connect to: `https://ai.solterrasol.com:11434`

**Important Questions:**
- Is Ollama actually running on this domain?
- If not, where should it connect to?
- Do you need help setting up Ollama on your VM?

### 2. Update Configuration (if needed)
Edit `ai-assistant/config/sunny-config.php` to change:
- Ollama URL
- Model name
- Timeout settings
- UI customizations

## Testing Steps

1. **Upload all files** to your GoDaddy hosting
2. **Test the connection** by visiting your portal and checking if Sunny appears
3. **Check browser console** for any JavaScript errors
4. **Test a simple message** to see if streaming works

## Troubleshooting

### Common Issues:

1. **"Unable to connect to Sunny"**
   - Check if all files were uploaded correctly
   - Verify file permissions (should be 644 for PHP files)
   - Check if Ollama service is accessible

2. **"Authentication required"**
   - Make sure you're logged into the portal
   - Check if session variables are set correctly

3. **No streaming response**
   - Verify Ollama endpoint is reachable
   - Check PHP error logs in cPanel
   - Test the connection endpoint directly

### File Permissions:
Set these permissions after upload:
- PHP files: 644
- Directories: 755

### PHP Requirements:
- PHP 7.4+ (should be available on GoDaddy)
- cURL extension (typically enabled)
- Session support (standard)

## Next Steps After Upload:

1. **Test basic functionality**
2. **Configure Ollama endpoint** (if different)
3. **Customize UI settings** in config file
4. **Monitor PHP error logs** for any issues

## Support

If you encounter issues:
1. Check browser developer console for JavaScript errors
2. Check cPanel error logs for PHP errors
3. Test the `/api/test-connection.php` endpoint directly
4. Verify all files uploaded correctly

## Architecture Notes

- **No Node.js required** - Pure PHP implementation
- **Server-Sent Events** for real-time streaming
- **Role-based security** integrated with existing portal
- **Database tools** for logistics data access
- **Configurable** via PHP config file 