
import * as React from 'react';

export default function ChatbotPage() {
  return (
    <div className="flex flex-col min-h-screen">
      {/* Replicated Header Structure from existing page */}
      <div className="sticky top-0 z-50 bg-background shadow-sm">
        <div className="main-header border-b border-t-2 border-black">
          <header className="mx-auto container pt-20 pb-2 px-4 text-left">
            <div className="w-full h-[0px] bg-black"></div>
            <h1 className="w-[200px] sm:w-full leading-none text-lg sm:text-xl md:text-2xl font-headline font-bold mb-3">
              Masses in honour of St. Josemaria, 2025
            </h1>
            {/* Dynamic summary buttons are omitted for this page */}
          </header>
        </div>
        {/* Filter input section is not included */}
      </div>

      {/* Iframe Container - takes remaining vertical space */}
      <div className="flex-1 flex flex-col">
        <iframe
          src="https://ai-chatbot-aetheris.vercel.app"
          title="AI Chatbot"
          className="w-full flex-1 border-0"
          allowFullScreen
        ></iframe>
      </div>
    </div>
  );
}
