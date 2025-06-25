# GoDaddy Deployment Instructions for Sunny AI Assistant

## 📋 Pre-Deployment Checklist

✅ Ollama-specific files removed  
✅ OpenAI configuration updated  
✅ API key configured  
✅ Ready for production deployment  

## 🚀 Deployment Steps

### 1. Upload Files to GoDaddy
Upload the entire `ai-assistant` folder to your GoDaddy hosting under:
```
/public_html/Solterra-Logistics-Portal/ai-assistant/
```

### 2. Configure API Key

**The OpenAI API key is now handled by your existing env.php file!** 

✅ **Already configured** - The API key has been added to your main `env.php` file alongside your Google Maps API key.

**No additional GoDaddy setup required** - The AI assistant now uses the same configuration system as your other API keys.

### 3. Create Logs Directory
Create a `logs` directory in the ai-assistant folder:
```
/public_html/Solterra-Logistics-Portal/ai-assistant/logs/
```

### 4. Set Permissions
Set proper permissions via cPanel File Manager:
- `logs/` directory: 755
- All PHP files: 644
- Config files: 644

### 5. Test Deployment
1. Visit: `https://yourdomain.com/Solterra-Logistics-Portal/ai-assistant/api/test-connection.php`
2. Should return JSON with connection status
3. Test the chat interface from your logistics portal

## 🔧 Troubleshooting

### Common Issues:

**"API key not found" error:**
- Verify environment variable is set correctly
- Check .env file exists and has correct permissions

**"Connection failed" error:**
- Ensure all files uploaded correctly
- Check PHP version (requires PHP 7.4+)

**Chat not loading:**
- Verify JavaScript files are accessible
- Check browser console for errors

## 📁 Deployed File Structure
```
ai-assistant/
├── api/
│   ├── chat-stream.php
│   ├── openai-client.php
│   ├── test-connection.php
│   ├── sunny-tools.php
│   └── query-executor.php
├── components/
│   ├── sunny-chat.js
│   ├── sunny-chat.css
│   └── sunny-chat.php
├── config/
│   └── sunny-config.php
├── logs/ (create this)
├── .env (rename from example.env)
├── DEPLOYMENT.md
├── MIGRATION_GUIDE.md
├── README.md
└── sunny_system_prompt.md
```

## ✅ Success Indicators
- ✅ Test connection returns successful JSON
- ✅ Chat interface loads in logistics portal
- ✅ Sunny responds to messages
- ✅ Database tools work correctly
- ✅ Cost tracking logs properly

## 🆘 Support
If you encounter issues, check:
1. PHP error logs in cPanel
2. Browser console for JavaScript errors
3. OpenAI API status page
4. Environment variable configuration 