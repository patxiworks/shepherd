
'use server';

import { type NextRequest, NextResponse } from 'next/server';
// For actual Flickr upload, you might use a library or make direct HTTP requests.
// If making direct requests, Node.js built-in 'fetch' (available in Next.js Edge/Node.js runtimes)
// and 'FormData' can be used. For OAuth 1.0a signing, you might need a library like 'oauth-1.0a'
// and 'crypto' for hashing.

// IMPORTANT: Store these securely as environment variables.
// NEVER hardcode them.
// const FLICKR_API_KEY = process.env.FLICKR_API_KEY;
// const FLICKR_API_SECRET = process.env.FLICKR_API_SECRET;
// For uploading to your own account, you'll also need your own:
// const FLICKR_ACCESS_TOKEN = process.env.FLICKR_ACCESS_TOKEN;
// const FLICKR_ACCESS_TOKEN_SECRET = process.env.FLICKR_ACCESS_TOKEN_SECRET;

// Flickr API Upload Endpoint (confirm the latest from Flickr documentation)
// const FLICKR_UPLOAD_ENDPOINT = 'https://up.flickr.com/services/upload/';
// Or for the newer API: 'https://www.flickr.com/services/upload/'

export async function POST(request: NextRequest) {
  try {
    const requestFormData = await request.formData();
    const photo = requestFormData.get('photo') as File | null;
    const title = requestFormData.get('title') as string | null;
    // const description = requestFormData.get('description') as string | null; // If you want to use this

    if (!photo) {
      return NextResponse.json({ message: 'No photo uploaded' }, { status: 400 });
    }
    if (!title) {
        return NextResponse.json({ message: 'Title is required for the photo' }, { status: 400 });
    }

    // *********************************************************************
    // ACTUAL FLICKR UPLOAD LOGIC WOULD GO HERE
    // The following is a conceptual outline. You'll need to implement it
    // using Flickr's API documentation.
    // *********************************************************************

    // 1. Prepare Authentication Parameters (Example for OAuth 1.0a)
    //    This is complex and usually involves:
    //    - Nonce generation
    //    - Timestamp
    //    - Signature method (HMAC-SHA1)
    //    - Signing the request with your consumer secret and access token secret.
    //    - A library like `oauth-1.0a` can help with signature generation.
    //    Alternatively, if Flickr has a simpler API key based upload or OAuth 2.0 for this, follow that.

    // 2. Create FormData for the Upload
    // const flickrUploadFormData = new FormData();
    // flickrUploadFormData.append('photo', photo, photo.name);
    // flickrUploadFormData.append('title', title);
    // if (description) flickrUploadFormData.append('description', description);
    // flickrUploadFormData.append('api_key', FLICKR_API_KEY!);
    // flickrUploadFormData.append('auth_token', FLICKR_ACCESS_TOKEN!); // Or other auth params
    // flickrUploadFormData.append('format', 'json'); // Request JSON response
    // flickrUploadFormData.append('nojsoncallback', '1'); // For raw JSON
    // ... add other OAuth parameters like oauth_consumer_key, oauth_nonce,
    //     oauth_signature, oauth_signature_method, oauth_timestamp, oauth_token, oauth_version

    // 3. Make the POST request to Flickr
    // const flickrResponse = await fetch(FLICKR_UPLOAD_ENDPOINT, {
    //   method: 'POST',
    //   body: flickrUploadFormData,
    //   // Headers might not be explicitly needed if FormData sets Content-Type correctly,
    //   // but Flickr might have specific requirements.
    // });

    // 4. Handle Flickr's Response
    // if (!flickrResponse.ok) {
    //   const errorText = await flickrResponse.text();
    //   console.error('Flickr API Error:', flickrResponse.status, errorText);
    //   throw new Error(`Flickr upload failed: ${flickrResponse.statusText} - ${errorText}`);
    // }

    // const flickrResult = await flickrResponse.json(); // Or .text() if XML

    // if (flickrResult.stat !== 'ok') {
    //   console.error('Flickr API Error:', flickrResult.message);
    //   throw new Error(`Flickr upload error: ${flickrResult.message}`);
    // }

    // const photoId = flickrResult.photoid?._content || flickrResult.photoid; // Structure might vary
    // if (!photoId) {
    //   console.error('Flickr response missing photo ID:', flickrResult);
    //   throw new Error('Failed to get photo ID from Flickr response.');
    // }

    // 5. Construct the Image URL (Example - check Flickr's latest URL format)
    //    You'll need the photo ID, server ID, and secret from Flickr's response.
    //    The following is a common pattern, but you'll need to extract these from flickrResult:
    //    const serverId = '...'; // e.g., flickrResult.photo.server
    //    const secret = '...';   // e.g., flickrResult.photo.secret
    //    const sizeSuffix = 'b'; // 'b' for large, 'm' for medium, etc.
    //    const actualFlickrImageUrl = `https://live.staticflickr.com/${serverId}/${photoId}_${secret}_${sizeSuffix}.jpg`;

    // *********************************************************************
    // END OF ACTUAL FLICKR UPLOAD LOGIC SECTION
    // *********************************************************************

    // For now, we continue to simulate a successful upload and return a placeholder URL.
    // Replace `simulatedFlickrUrl` with `actualFlickrImageUrl` once implemented.
    const simulatedFlickrUrl = `https://placehold.co/600x400.png?text=${encodeURIComponent("Flickr:"+title.substring(0,20))}`;
    const hint = title.toLowerCase().split(' ').slice(0,2).join(' ');


    return NextResponse.json({
      message: 'Photo processed (simulated Flickr upload)',
      imageUrl: simulatedFlickrUrl, // Replace with actualFlickrImageUrl
      altText: title,
      hint: hint,
      // You might also want to return the flickrPhotoId if you store it
      // flickrPhotoId: photoId
    }, { status: 200 });

  } catch (error) {
    console.error('Error in image upload endpoint:', error);
    const errorMessage = error instanceof Error ? error.message : 'Failed to process image';
    return NextResponse.json({ message: errorMessage }, { status: 500 });
  }
}
